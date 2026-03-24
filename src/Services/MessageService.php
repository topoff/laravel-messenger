<?php

namespace Topoff\Messenger\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Contracts\MessageReceiverInterface;
use Topoff\Messenger\MailHandler\MainMailHandler;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Repositories\MessageTypeRepository;

/**
 * One of these functions are necessary to be called at least: create(), changeScheduleOfExisting() or delete()
 */
class MessageService
{
    protected MessageTypeRepository $messageTypeRepository;

    protected ?string $senderClass = null;

    protected ?int $senderId = null;

    protected ?string $receiverClass = null;

    protected ?int $receiverId = null;

    protected ?string $messagableClass = null;

    protected ?int $messagableId = null;

    protected ?string $messageTypeClass = null;

    protected ?MessageType $messageType = null;

    protected ?int $companyId = null;

    protected ?Carbon $scheduled = null;

    protected ?array $params = null;

    protected ?string $locale = null;

    /**
     * Initialized as false, when a receiver is set it is set to true
     * that it can be warned if it's initialized but not an action executed
     */
    protected bool $actionMissing = false;

    public function __construct()
    {
        $this->messageTypeRepository = app(MessageTypeRepository::class);
    }

    public function __destruct()
    {
        if ($this->actionMissing) {
            Log::error('MessageService: Destroyed without calling create(), change() or delete().', [
                'message_type_class' => $this->messageTypeClass,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);
        }
    }

    public function setSender(?string $senderClass = null, ?int $senderId = null): self
    {
        $this->senderClass = $senderClass;
        $this->senderId = $senderId;

        return $this;
    }

    public function setReceiver(?string $receiverClass = null, ?int $receiverId = null): self
    {
        $this->actionMissing = true;

        $this->receiverClass = $receiverClass;
        $this->receiverId = $receiverId;

        return $this;
    }

    public function setMessagable(?string $messagableClass = null, ?int $messagableId = null): self
    {
        $this->messagableClass = $messagableClass;
        $this->messagableId = $messagableId;

        return $this;
    }

    public function setMessageTypeClass(?string $messageTypeClass = null): self
    {
        $this->messageTypeClass = $messageTypeClass;

        $this->messageType = $this->messageTypeRepository->getFromTypeAndCustomer($this->messageTypeClass);

        return $this;
    }

    public function setCompanyId(?int $companyId = null): self
    {
        $this->companyId = $companyId;

        return $this;
    }

    public function setScheduled(?Carbon $scheduled = null): self
    {
        $this->scheduled = $scheduled;

        return $this;
    }

    public function setMailText(?string $mailText = null): self
    {
        $this->params = array_merge($this->params ?? [], ['text' => $mailText]);

        return $this;
    }

    public function setParams(?array $params = null): self
    {
        $this->params = $params;

        return $this;
    }

    public function setLocale(?string $locale = null): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function setLanguage(?string $language = null): self
    {
        return $this->setLocale($language);
    }

    /**
     * Has to be called as last function, eventually creates the new Message DB Record
     *
     * One of these functions are necessary: create(), changeScheduleOfExisting() or delete()
     */
    public function create(): void
    {
        $this->scheduled ??= $this->getScheduled();
        $this->locale ??= $this->resolveReceiverLocale();

        $this->reportMissingParams();

        if (! $this->preventCreateMessage()) {
            $messageClass = config('messenger.models.message');
            $messageClass::create([
                'sender_type' => $this->senderClass,
                'sender_id' => $this->senderId,
                'receiver_type' => $this->receiverClass,
                'receiver_id' => $this->receiverId,
                'company_id' => $this->companyId,
                'message_type_id' => $this->messageType->id,
                'messagable_type' => $this->messagableClass,
                'messagable_id' => $this->messagableId,
                'params' => $this->params,
                'locale' => $this->locale,
                'scheduled_at' => $this->scheduled,
            ]);
        }

        $this->resetVars();
    }

    /**
     * Create the Message DB Record and immediately send it (synchronously).
     * Useful for time-sensitive emails like password resets.
     */
    public function createAndSendNow(): void
    {
        $this->scheduled ??= $this->getScheduled();
        $this->locale ??= $this->resolveReceiverLocale();

        $this->reportMissingParams();

        if (! $this->preventCreateMessage()) {
            $messageClass = config('messenger.models.message');
            /** @var Message $message */
            $message = $messageClass::create([
                'sender_type' => $this->senderClass,
                'sender_id' => $this->senderId,
                'receiver_type' => $this->receiverClass,
                'receiver_id' => $this->receiverId,
                'company_id' => $this->companyId,
                'message_type_id' => $this->messageType->id,
                'messagable_type' => $this->messagableClass,
                'messagable_id' => $this->messagableId,
                'params' => $this->params,
                'locale' => $this->locale,
                'scheduled_at' => null,
            ]);

            $message->load('messageType');
            $handlerClass = $message->messageType->single_handler;

            if ($handlerClass && class_exists($handlerClass)) {
                /** @var MainMailHandler $handler */
                $handler = new $handlerClass($message);
                $handler->send();
            }
        }

        $this->resetVars();
    }

    /**
     * Change the scheduling of an existing Message DB Record
     *
     * One of these functions are necessary: create(), change() or delete()
     */
    public function change(): ?Message
    {
        $messageClass = config('messenger.models.message');
        $message = $messageClass::where('receiver_type', $this->receiverClass)->where('receiver_id', $this->receiverId)
            ->where('company_id', $this->companyId)
            ->where('message_type_id', $this->messageType->id)
            ->where('messagable_type', $this->messagableClass)
            ->where('messagable_id', $this->messagableId)
            ->first();

        if ($message) {
            $message->scheduled_at = $this->scheduled;
            $message->save();

            $this->resetVars();

            return $message;
        }

        Log::error('MessageService::change(): No existing message found to reschedule.', [
            'receiver_class' => $this->receiverClass,
            'receiver_id' => $this->receiverId,
            'message_type_id' => $this->messageType->id,
            'messagable_class' => $this->messagableClass,
            'messagable_id' => $this->messagableId,
        ]);

        return null;

    }

    /**
     * Deletes a message. Mostly if the message case has been deleted, z.B a quote
     *
     * One of these functions are necessary: create(), changeScheduleOfExisting() or delete()
     */
    public function delete(): ?bool
    {
        $this->locale ??= $this->resolveReceiverLocale();

        $this->reportMissingParams();

        $messageClass = config('messenger.models.message');
        $result = $messageClass::create([
            'sender_type' => $this->senderClass,
            'sender_id' => $this->senderId,
            'receiver_type' => $this->receiverClass,
            'receiver_id' => $this->receiverId,
            'company_id' => $this->companyId,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => $this->messagableClass,
            'messagable_id' => $this->messagableId,
            'locale' => $this->locale,
            'sent_at' => null,
        ])->delete();

        $this->resetVars();

        return $result;
    }

    /**
     * Determine if a message creation should be prevented.
     * Configurable via config('messenger.sending.prevent_create_message').
     */
    protected function preventCreateMessage(): bool
    {
        $checker = config('messenger.sending.prevent_create_message');

        if (is_callable($checker)) {
            return $checker($this->receiverClass, $this->receiverId);
        }

        return false;
    }

    /**
     * Override in app to provide custom scheduling logic for specific message types.
     */
    protected function getScheduled(): ?Carbon
    {
        return null;
    }

    /**
     * Checks if all required params for this mail are set
     */
    private function reportMissingParams(): bool
    {
        if ($this->messageType->required_messagable && (in_array($this->messagableClass, [null, '', '0'], true) || ($this->messagableId === null || $this->messagableId === 0))) {
            Log::error('MessageService: Required Messagable parameter is missing, message will not be created.', [
                'message_type_class' => $this->messageTypeClass,
                'sender' => $this->senderClass.' '.$this->senderId,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);

            return false;
        }

        if ($this->messageType->required_sender && (in_array($this->senderClass, [null, '', '0'], true) || ($this->senderId === null || $this->senderId === 0))) {
            Log::error('MessageService: Required Sender parameter is missing, message will not be created.', [
                'message_type_class' => $this->messageTypeClass,
                'sender' => $this->senderClass.' '.$this->senderId,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);

            return false;
        }

        if ($this->messageType->required_company_id && ($this->companyId === null || $this->companyId === 0)) {
            Log::error('MessageService: Required CompanyId parameter is missing, message will not be created.', [
                'message_type_class' => $this->messageTypeClass,
                'sender' => $this->senderClass.' '.$this->senderId,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);

            return false;
        }

        if ($this->messageType->required_text && in_array(data_get($this->params, 'text'), [null, '', '0'], true)) {
            Log::error('MessageService: Required MailText parameter is missing, message will not be created.', [
                'message_type_class' => $this->messageTypeClass,
                'sender' => $this->senderClass.' '.$this->senderId,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);

            return false;
        }

        if ($this->messageType->required_scheduled && ! $this->scheduled instanceof Carbon) {
            Log::error('MessageService: Required Scheduled parameter is missing, message will not be created.', [
                'message_type_class' => $this->messageTypeClass,
                'sender' => $this->senderClass.' '.$this->senderId,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);

            return false;
        }

        if ($this->messageType->required_params && ($this->params === null || $this->params === [])) {
            Log::error('MessageService: Required Params parameter is missing, message will not be created.', [
                'message_type_class' => $this->messageTypeClass,
                'sender' => $this->senderClass.' '.$this->senderId,
                'receiver' => $this->receiverClass.' '.$this->receiverId,
            ]);

            return false;
        }

        return true;
    }

    protected function resolveReceiverLocale(): ?string
    {
        if (! $this->receiverClass || ! $this->receiverId || ! class_exists($this->receiverClass)) {
            return null;
        }

        $receiver = $this->receiverClass::query()->find($this->receiverId);

        return $receiver instanceof MessageReceiverInterface
            ? $receiver->preferredLocale()
            : null;
    }

    /**
     *  Reset the vars to initial state - needed if multiple messages are generated through the same instance of MessageService
     */
    private function resetVars(): void
    {
        $this->senderClass = null;
        $this->senderId = null;
        $this->receiverClass = null;
        $this->receiverId = null;
        $this->messagableClass = null;
        $this->messagableId = null;
        $this->messageTypeClass = null;
        $this->companyId = null;
        $this->scheduled = null;
        $this->params = null;
        $this->locale = null;
        $this->actionMissing = false;
    }
}
