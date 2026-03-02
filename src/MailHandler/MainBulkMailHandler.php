<?php

namespace Topoff\MailManager\MailHandler;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Topoff\MailManager\Contracts\GroupableMailTypeInterface;
use Topoff\MailManager\Contracts\MessageReceiverInterface;
use Topoff\MailManager\Exceptions\MissingGroupableMailTypeInterfaceException;

/**
 * All Mails which should be sent als BulkMails with this MainBulkMailHandler
 * MUST implement the @see GroupableMailTypeInterface::class
 *
 * It's also possible to extend this MainBulkMailHandler to send one / many
 * MessageTypes with a separate BulkMail. In this case the custom BulkMailHandler
 * must be set in the table: message_types.bulk_mail_handler.
 */
class MainBulkMailHandler
{
    /**
     * BulkMailBuilder constructor.
     */
    public function __construct(protected MessageReceiverInterface $receiver, protected Collection $messageGroup) {}

    /**
     * Overwrite in child mailer class, if you want to use another MailClass
     */
    public function mailClass(): string
    {
        $class = config('mail-manager.mail.default_bulk_mail_class');

        if (! $class) {
            throw new \RuntimeException('No bulk mail class configured. Set mail-manager.mail.default_bulk_mail_class in config.');
        }

        return $class;
    }

    public function send(): void
    {
        /** @var array<int|string, MainMailHandler> $handlers */
        $handlers = [];

        try {
            $this->setMessagesToReserved();

            foreach ($this->messageGroup as $message) {
                /** @var GroupableMailTypeInterface|MainMailHandler $mailHandler */
                $mailHandler = (new $message->messageType->single_mail_handler($message));
                $this->throwExceptionOnMissingInterface($mailHandler);
                $handlers[$message->getKey()] = $mailHandler;
            }

            $mailClass = $this->mailClass();
            Mail::to($this->receiver->getEmail())->send(new $mailClass(...$this->getMailParameters()));
            $this->setMessagesToSent();
        } catch (Throwable $t) {
            $this->setMessagesToError();
            Log::error('Messages could not be sent', [
                'receiver' => $this->receiver->getEmail(),
                'exception' => $t,
            ]);

            return;
        }

        foreach ($handlers as $key => $handler) {
            try {
                $handler->onSuccessfulSent();
            } catch (Throwable $t) {
                Log::warning('Post-send hook failed', [
                    'message_id' => $key,
                    'exception' => $t,
                ]);
            }
        }
    }

    /**
     * Get the parameters for the mail
     * Can be overwritten in child BulkMailHandlers
     */
    protected function getMailParameters(): array
    {
        return [$this->receiver, $this->messageGroup];
    }

    /**
     * Sets the message to reserved
     */
    protected function setMessagesToReserved(): void
    {
        $messageClass = config('mail-manager.models.message');
        $messageClass::whereIn('id', $this->messageGroup->pluck('id'))->update(['reserved_at' => Date::now()]);
    }

    /**
     * Sets the message to error
     */
    protected function setMessagesToError(): void
    {
        $messageClass = config('mail-manager.models.message');
        $messageClass::whereIn('id', $this->messageGroup->pluck('id'))->update(['reserved_at' => null, 'error_at' => Date::now()]);
    }

    /**
     * Sets the message to sent
     */
    protected function setMessagesToSent(): void
    {
        $messageClass = config('mail-manager.models.message');
        $messageClass::whereIn('id', $this->messageGroup->pluck('id'))->update(['sent_at' => Date::now(), 'error_at' => null]);
    }

    /**
     * @throws MissingGroupableMailTypeInterfaceException
     */
    protected function throwExceptionOnMissingInterface(MainMailHandler $mailHandler): void {}
}
