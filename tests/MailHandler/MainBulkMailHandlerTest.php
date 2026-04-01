<?php

use Illuminate\Support\Facades\Mail;
use Topoff\Messenger\Mail\BulkMail;
use Topoff\Messenger\MailHandler\MainBulkMailHandler;
use Topoff\Messenger\Models\Message;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;

beforeEach(function () {
    $this->messageType = createMessageType(['direct' => false]);
    $this->receiver = createReceiver();
    $this->messagable = createMessagable();
});

it('sends a bulk mail to the receiver', function () {
    Mail::fake();

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => $this->messagable->id,
        ]),
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable(['title' => 'Second'])->id,
        ]),
    ]);

    // Load the messageType relationship
    $messages->each(fn (Message $m) => $m->load('messageType'));

    $handler = new MainBulkMailHandler($this->receiver, $messages);
    $handler->send();

    Mail::assertSent(BulkMail::class, fn (BulkMail $mail) => $mail->hasTo($this->receiver->email));
});

it('sets all messages to reserved before sending', function () {
    Mail::fake();

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => $this->messagable->id,
        ]),
    ])->each(fn (Message $m) => $m->load('messageType'));

    $handler = new MainBulkMailHandler($this->receiver, $messages);
    $handler->send();

    // After successful send, sent_at should be set
    $messages->each(function (Message $m) {
        $m->refresh();
        expect($m->sent_at)->not->toBeNull();
    });
});

it('marks all messages as sent on success', function () {
    Mail::fake();

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => $this->messagable->id,
        ]),
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable(['title' => 'Another'])->id,
        ]),
    ])->each(fn (Message $m) => $m->load('messageType'));

    $handler = new MainBulkMailHandler($this->receiver, $messages);
    $handler->send();

    $messages->each(function (Message $m) {
        $m->refresh();
        expect($m->sent_at)->not->toBeNull();
    });
});

it('marks all messages as error and rethrows when sending fails', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP failure'));

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => $this->messagable->id,
        ]),
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable(['title' => 'Error'])->id,
        ]),
    ])->each(fn (Message $m) => $m->load('messageType'));

    $handler = new MainBulkMailHandler($this->receiver, $messages);

    try {
        $handler->send();
    } catch (RuntimeException) {
        // Expected
    }

    $messages->each(function (Message $m) {
        $m->refresh();
        expect($m->error_at)->not->toBeNull()
            ->and($m->reserved_at)->toBeNull();
    });
});

it('rethrows the exception when sending fails', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP failure'));

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => $this->messagable->id,
        ]),
    ])->each(fn (Message $m) => $m->load('messageType'));

    $handler = new MainBulkMailHandler($this->receiver, $messages);

    expect(fn () => $handler->send())->toThrow(RuntimeException::class, 'SMTP failure');
});

it('uses the configured bulk mail class', function () {
    $handler = new MainBulkMailHandler($this->receiver, collect());

    expect($handler->mailClass())->toBe(BulkMail::class);
});

it('throws when no bulk mail class is configured', function () {
    config()->set('messenger.mail.default_bulk_mail_class');

    $handler = new MainBulkMailHandler($this->receiver, collect());

    expect(fn () => $handler->mailClass())->toThrow(RuntimeException::class);
});
