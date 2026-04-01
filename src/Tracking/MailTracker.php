<?php

namespace Topoff\Messenger\Tracking;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
use Topoff\Messenger\Models\Message;

class MailTracker
{
    public function messageSending(MessageSending $event): void
    {
        try {
            $messageModels = $this->resolveMessageModels($event);
            if ($messageModels->isEmpty()) {
                Log::error('MailTracker: No message models resolved, email will be sent without tracking.', [
                    'to' => collect($event->message->getTo())->map(fn ($a) => $a->getAddress())->implode(', '),
                    'subject' => $event->message->getSubject(),
                ]);

                return;
            }

            $message = $event->message;
            if ($message->getHeaders()->has('X-No-Track')) {
                return;
            }

            $hash = $this->generateHash();
            $message->getHeaders()->addTextHeader('X-Mailer-Hash', $hash);

            [$html, $mutated] = $this->injectTrackers($message, $hash);

            $this->injectConfigurationSetHeader($messageModels, $message);
            $this->injectFromAddressOverride($messageModels, $message);
            $this->injectReplyToOverride($messageModels, $message);
            $this->persistTrackingMetadata($messageModels, $message, $hash, $html, $mutated);
            $this->injectMessageTags($messageModels, $message);
        } catch (\Throwable $e) {
            Log::error('MailTracker: Failed to inject tracking into email, sending without tracking.', [
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    public function messageSent(MessageSent $event): void
    {
        try {
            $originalMessage = $event->sent->getOriginalMessage();
            if (! $originalMessage instanceof Email) {
                return;
            }

            $hash = $originalMessage->getHeaders()->get('X-Mailer-Hash')?->getBodyAsString();
            if (! $hash) {
                return;
            }

            /** @var Collection<int, Message> $messages */
            $messages = $this->messageModelClass()::query()->where('tracking_hash', $hash)->get();
            if ($messages->isEmpty()) {
                return;
            }

            $messageId = $this->resolveMessageId($event->sent);
            $this->messageModelClass()::query()->where('tracking_hash', $hash)->update([
                'tracking_message_id' => $messageId,
            ]);
        } catch (\Throwable $e) {
            Log::error('MailTracker: Failed to persist tracking message ID after sending.', [
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    protected function resolveMessageModels(MessageSending $event): Collection
    {
        /** @var class-string<Message> $messageClass */
        $messageClass = $this->messageModelClass();

        $single = Arr::get($event->data, 'messageModel');
        if ($single instanceof $messageClass) {
            return collect([$single]);
        }

        $group = Arr::get($event->data, 'messages');
        if ($group instanceof Collection) {
            return $group
                ->filter(fn (mixed $message): bool => $message instanceof $messageClass)
                ->values();
        }

        return collect();
    }

    protected function messageModelClass(): string
    {
        return config('messenger.models.message');
    }

    protected function generateHash(): string
    {
        $messageClass = $this->messageModelClass();

        do {
            $hash = Str::random(32);
            $exists = $messageClass::query()->where('tracking_hash', $hash)->exists();
        } while ($exists);

        return $hash;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    protected function injectTrackers(Email $message, string $hash): array
    {
        $body = $message->getBody();

        if ($body instanceof AlternativePart || $body instanceof MixedPart || $body instanceof RelatedPart) {
            $newParts = [];
            $capturedHtml = '';
            $mutated = false;

            foreach ($body->getParts() as $part) {
                if ($part instanceof TextPart && $part->getMediaSubtype() === 'html') {
                    $capturedHtml = $part->getBody();
                    $trackedHtml = $this->addTrackers($capturedHtml, $hash);
                    $newParts[] = new TextPart($trackedHtml, $message->getHtmlCharset(), 'html');
                    $mutated = true;
                } else {
                    $newParts[] = $part;
                }
            }

            if ($mutated) {
                $class = $body::class;
                $message->setBody(new $class(...$newParts));
            }

            return [$capturedHtml, $mutated];
        }

        if ($body instanceof TextPart && $body->getMediaSubtype() === 'html') {
            $originalHtml = $body->getBody();
            $message->setBody(new TextPart(
                $this->addTrackers($originalHtml, $hash),
                $message->getHtmlCharset(),
                'html'
            ));

            return [$originalHtml, true];
        }

        return ['', false];
    }

    protected function addTrackers(string $html, string $hash): string
    {
        $result = $html;

        if (config('messenger.tracking.inject_pixel')) {
            $pixelUrl = route('messenger.tracking.open', ['hash' => $hash]);
            $pixelTag = '<img border=0 width=1 alt="" height=1 src="'.$pixelUrl.'" />';
            $result = preg_match('/^(.*<body[^>]*>)(.*)$/is', $result, $matches)
                ? $matches[1].$pixelTag.$matches[2]
                : $result.$pixelTag;
        }

        if (config('messenger.tracking.track_links')) {
            $result = preg_replace_callback('/(<a[^>]*href=["\'])([^"\']*)/i', function (array $matches) use ($hash): string {
                $url = $matches[2] !== '' ? str_replace('&amp;', '&', $matches[2]) : url('/');

                return $matches[1].URL::signedRoute('messenger.tracking.click', [
                    'l' => $url,
                    'h' => $hash,
                ]);
            }, $result) ?? $result;
        }

        return $result;
    }

    /**
     * @param  Collection<int, Message>  $messageModels
     */
    protected function persistTrackingMetadata(Collection $messageModels, Email $message, string $hash, string $originalHtml, bool $mutated): void
    {
        $to = collect($message->getTo())->first();
        $from = collect($message->getFrom())->first();

        $messageModels->each(function (Message $messageModel) use ($from, $to, $message, $hash, $mutated, $originalHtml): void {
            $messageModel->tracking_hash = $hash;
            $messageModel->tracking_sender_name = $from?->getName();
            $messageModel->tracking_sender_contact = $from?->getAddress();
            $messageModel->tracking_recipient_name = $to?->getName();
            $messageModel->tracking_recipient_contact = $to?->getAddress();
            $messageModel->tracking_subject = $message->getSubject();
            $messageModel->tracking_opens = 0;
            $messageModel->tracking_clicks = 0;
            $messageModel->tracking_meta ??= [];

            if ($mutated && config('messenger.tracking.log_content')) {
                $this->storeOriginalContent($messageModel, $hash, $originalHtml);
            }

            $messageModel->save();
        });
    }

    protected function storeOriginalContent(Message $messageModel, string $hash, string $html): void
    {
        $strategy = config('messenger.tracking.log_content_strategy', 'database');

        if ($strategy === 'filesystem') {
            $disk = config('messenger.tracking.tracker_filesystem');
            $folder = trim((string) config('messenger.tracking.tracker_filesystem_folder', 'messenger-tracker'), '/');
            $path = $folder.'/'.$hash.'.html';

            if ($disk) {
                Storage::disk($disk)->put($path, $html);
            } else {
                Storage::put($path, $html);
            }

            $messageModel->tracking_content_path = $path;
            $messageModel->tracking_content = null;

            return;
        }

        $maxSize = (int) config('messenger.tracking.content_max_size', 65535);
        $messageModel->tracking_content = mb_substr($html, 0, $maxSize);
        $messageModel->tracking_content_path = null;
    }

    /**
     * @param  Collection<int, Message>  $messageModels
     */
    protected function injectConfigurationSetHeader(Collection $messageModels, Email $message): void
    {
        if ($message->getHeaders()->has('X-SES-CONFIGURATION-SET')) {
            return;
        }

        $configSetKey = $messageModels->first()?->messageType?->ses_configuration_set;
        if (! $configSetKey) {
            return;
        }

        $sets = (array) config('messenger.ses_sns.configuration_sets', []);
        $awsName = $sets[$configSetKey]['configuration_set'] ?? null;

        if (! $awsName) {
            Log::warning("MailTracker: Unknown configuration set key '{$configSetKey}'");

            return;
        }

        $message->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', $awsName);
    }

    /**
     * @param  Collection<int, Message>  $messageModels
     */
    protected function injectFromAddressOverride(Collection $messageModels, Email $message): void
    {
        $configSetKey = $messageModels->first()?->messageType?->ses_configuration_set;
        if (! $configSetKey) {
            return;
        }

        $sets = (array) config('messenger.ses_sns.configuration_sets', []);
        $identityKey = $sets[$configSetKey]['identity'] ?? null;
        if (! $identityKey) {
            return;
        }

        $identities = (array) config('messenger.ses_sns.sending.identities', []);
        $mailFromAddress = trim((string) ($identities[$identityKey]['mail_from_address'] ?? ''));
        if ($mailFromAddress === '') {
            return;
        }

        $currentFrom = collect($message->getFrom())->first();
        $currentName = $currentFrom?->getName() ?? '';

        $message->from(new Address($mailFromAddress, $currentName));
    }

    /**
     * @param  Collection<int, Message>  $messageModels
     */
    protected function injectReplyToOverride(Collection $messageModels, Email $message): void
    {
        if ($message->getReplyTo() !== []) {
            return;
        }

        $configSetKey = $messageModels->first()?->messageType?->ses_configuration_set;
        if (! $configSetKey) {
            return;
        }

        $sets = (array) config('messenger.ses_sns.configuration_sets', []);
        $identityKey = $sets[$configSetKey]['identity'] ?? null;
        if (! $identityKey) {
            return;
        }

        $identities = (array) config('messenger.ses_sns.sending.identities', []);
        $replyToAddress = trim((string) ($identities[$identityKey]['reply_to_address'] ?? ''));
        if ($replyToAddress === '') {
            return;
        }

        $message->replyTo($replyToAddress);
    }

    /**
     * @param  Collection<int, Message>  $messageModels
     */
    protected function injectMessageTags(Collection $messageModels, Email $message): void
    {
        $configSetKey = $messageModels->first()?->messageType?->ses_configuration_set;
        if (! $configSetKey) {
            return;
        }

        if ($message->getHeaders()->has('X-SES-MESSAGE-TAGS')) {
            return;
        }

        $tenantName = trim((string) config('messenger.ses_sns.tenant.name', ''));
        $mailClass = class_basename($messageModels->first()?->messageType->notification_class ?? '');

        $tags = collect([
            'tenant_id' => $tenantName,
            'stream' => $configSetKey,
            'mail_type' => $mailClass,
        ])->filter(fn (string $v): bool => $v !== '')
            ->map(fn (string $v, string $k): string => "{$k}={$v}")
            ->implode(', ');

        if ($tags !== '') {
            $message->getHeaders()->addTextHeader('X-SES-MESSAGE-TAGS', $tags);
        }
    }

    protected function resolveMessageId(SentMessage $message): ?string
    {
        $originalMessage = $message->getOriginalMessage();
        if (! $originalMessage instanceof Email) {
            return $message->getMessageId();
        }

        $headers = $originalMessage->getHeaders();

        if (($header = $headers->get('X-SES-Message-ID')) instanceof HeaderInterface) {
            return $header->getBodyAsString();
        }

        return $message->getMessageId();
    }
}
