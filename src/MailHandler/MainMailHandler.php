<?php

namespace Topoff\Messenger\MailHandler;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Topoff\Messenger\Contracts\GroupableMailTypeInterface;
use Topoff\Messenger\Contracts\MessageReceiverInterface;
use Topoff\Messenger\Exceptions\ReceiverMissingException;
use Topoff\Messenger\Models\Message;

/**
 * Base / Parent class of all MailHandlers
 *
 * The purpose of this class is, to handle each message from its own builder:
 * - handle errors on message basis -> ex. set error_at in related table to this message
 * - handle sent on message basis -> ex. set status in related table to this message
 *
 * There are multiple functions which can be overwritten on the child classes, to handle errors, sent, etc:
 * - onBuilding() -> optional, can be overwritten
 * - onError() -> must be implemented
 * - sentSuccessful() -> must be implemented
 *
 * They are even called for every line in a bulk mail with multiple messages
 *
 * @see \Topoff\Messenger\MailHandler\MainBulkMailHandler::send()
 */
class MainMailHandler implements GroupableMailTypeInterface
{
    protected MessageReceiverInterface $receiver;

    /**
     * Message should be eager loaded with('messageType')
     */
    public function __construct(protected Message $message)
    {
        $this->setMessageReserved();
        $this->onBuilding();
    }

    /**
     * Determine if the message can / should be sent now
     */
    public function shouldBeSentNow(): bool
    {
        return true;
    }

    public function shouldBeSentInThisEnvironment(): bool
    {
        $checker = config('messenger.sending.check_should_send');

        if (is_callable($checker)) {
            return $checker();
        }

        return App::environment('production');
    }

    /**
     * Determine if the message should be aborted and deleted before sending
     */
    public function abortAndDeleteWhen(): bool
    {
        $messageType = $this->message->messageType;
        $messagable = $this->message->messagable;
        if ($messageType->required_messagable && ! $messagable) {
            $this->message->error_message = 'Message has been deleted, because the Messagable itself is missing but required.';
            $this->message->save();

            return true;
        }

        if (! $this->receiver->getEmailIsValid()) {
            $this->message->error_message = 'Message has been deleted, because the receiver is trashed or the receiver email is invalid (email_invalid_at is not null).';
            $this->message->save();

            return true;
        }

        return false;
    }

    /**
     * Overwrite if you want to do something after sending the message successfully
     */
    public function onSuccessfulSent(): void
    {
        // OPTIONAL in child classes: Overwrite if you want to do something after sending the message successfully
    }

    /**
     * Overwrite if you want to do something when the sending failed
     *
     * @return bool -> if the Error has been handled here or not
     */
    public function onError(Throwable $t, Collection $params): bool
    {
        // OPTIONAL in child classes: Overwrite if you want to do something else when the sending failed

        return false;
    }

    /**
     * Optional, implement what to do on building the message
     */
    public function onBuilding(): void
    {
        // OPTIONAL in child classes: Overwrite if you want to do something before building the message
    }

    /**
     * Send the message
     */
    public function send(): void
    {
        try {
            $this->message->attempts++;
            $this->receiver = $this->getReceiver();

            if (! $this->shouldBeSentNow()) {
                $this->message->save();

                return;
            }

            if ($this->abortAndDeleteWhen()) {
                $this->message->save();
                $this->message->delete();

                return;
            }

            $mailClass = $this->mailClass();
            $mail = new $mailClass(...$this->getMailParameters());
            if ($this->shouldBeSentInThisEnvironment()) {
                $mailerName = $this->resolveMailerName();
                $pendingMail = $mailerName
                    ? Mail::mailer($mailerName)->to($this->receiver->getEmail())
                    : Mail::to($this->receiver->getEmail());
                $pendingMail->locale($this->receiverLocale())->send($mail);
            }
            $this->setMessageSent();
            $this->onSuccessfulSent();
        } catch (Throwable $t) {
            $this->message->reserved_at = null;
            $this->message->error_code = (int) $t->getCode();

            if ($t instanceof ReceiverMissingException) {
                Log::notice(static::class.':'.__FUNCTION__.': Message could not be sent because the receiver is missing, presumably trashed. message_id: '.$this->message->id.' class: '.$this->mailClass());
                $this->message->error_message = 'Message could not be sent because the receiver is missing, presumably trashed.';
                $this->message->error_at = now();
                $this->message->deleted_at = now();
                $this->message->save();
            } elseif ($this->isPermanentFailure($t)) {
                $this->message->failed_at = now();
                $this->message->error_message = Str::limit(($t->getCode().' : '.$t->getMessage()), 245);
                $this->message->save();

                Log::warning(static::class.':'.__FUNCTION__.': Message permanently failed: message_id: '.$this->message->id.' class: '.$this->mailClass().' - Code: '.$t->getCode().' Message: '.$t->getMessage());
            } else {
                $this->message->error_at = now();
                $this->message->error_message = Str::limit(($t->getCode().' : '.$t->getMessage()), 245);
                $this->message->save();

                $params = collect(...$this->getMailParameters());
                $errorHasBeenHandled = $this->onError($t, $params);

                if (! $errorHasBeenHandled) {
                    Log::error(static::class.':'.__FUNCTION__.': Messages could not be sent: message_id: '.$this->message->id.' class: '.$this->mailClass().' - Code: '.$t->getCode().' Message: '
                        .$t->getMessage().' on line '.$t->getLine(), ['params' => $params->toArray(), 'trace' => $t->getTrace()]);
                }
            }
        }
    }

    /**
     * Get the parameters for the mail
     * Can be overwritten in child MailHandlers
     */
    public function getMailParameters(): array
    {
        return [$this->message]; // maps to the first parameter of the __construct of the mail class, named $messageModel there
    }

    /**
     * Return the message Line for the Bulk Mail
     */
    public function buildDataBulkMail(): string
    {
        return $this->message->messageType->bulk_message_line;
    }

    /**
     * Determine if the failure is permanent and should not be retried.
     */
    protected function isPermanentFailure(Throwable $t): bool
    {
        $code = (int) $t->getCode();

        // 550: mailbox doesn't exist / unroutable address
        // 553: mailbox name not allowed
        // 521: does not accept mail
        // 556: domain does not accept mail
        if (in_array($code, [550, 553, 521, 556], true)) {
            return true;
        }

        // SES rejection
        return Str::contains($t->getMessage(), 'MessageRejected');
    }

    /**
     * The mail class to use
     *
     * Overwrite to use a different mail class than in message_types defined
     */
    protected function mailClass(): string
    {
        return $this->message->messageType->notification_class;
    }

    /**
     * Sets the message to reserved
     */
    protected function setMessageReserved(): void
    {
        $this->message->reserved_at = Date::now();
        $this->message->save();
    }

    /**
     * Check if the receiver is ok
     *
     * @throws ReceiverMissingException
     */
    protected function getReceiver(): MessageReceiverInterface
    {
        /** @var MessageReceiverInterface $receiver */
        $receiver = $this->message->receiver;

        if (! $receiver) {
            throw new ReceiverMissingException('Receiver is missing', ReceiverMissingException::USER_DELETED);
        }

        return $receiver;
    }

    protected function receiverLocale(): string
    {
        return $this->message->locale ?: $this->receiver->preferredLocale();
    }

    /**
     * Resolve a custom mailer name from the message params.
     * Returns null to use the application default mailer.
     */
    protected function resolveMailerName(): ?string
    {
        $mailer = data_get($this->message->params, 'mailer');

        return ($mailer && is_string($mailer)) ? $mailer : null;
    }

    /**
     * Set the message to sent
     */
    protected function setMessageSent(): void
    {
        $this->message->sent_at = Date::now();
        $this->message->save();
    }
}
