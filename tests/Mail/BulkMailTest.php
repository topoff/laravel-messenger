<?php

use Topoff\Messenger\Mail\BulkMail;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;

beforeEach(function () {
    $this->messageType = createMessageType();
    $this->receiver = createReceiver();
});

it('sets the default subject from message count', function () {
    config()->set('messenger.mail.bulk_mail_subject');

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
    ]);

    $mail = new BulkMail($this->receiver, $messages);
    $mail->build();

    expect($mail->subject)->toBe('2 messages');
});

it('uses custom subject resolver from config', function () {
    config()->set('messenger.mail.bulk_mail_subject', fn ($receiver, $group) => 'Custom: '.$group->count().' items');

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
    ]);

    $mail = new BulkMail($this->receiver, $messages);
    $mail->build();

    expect($mail->subject)->toBe('Custom: 1 items');
});

it('sets url from config resolver', function () {
    config()->set('messenger.mail.bulk_mail_url', fn ($receiver) => 'https://example.com/receiver/'.$receiver->id);

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
    ]);

    $mail = new BulkMail($this->receiver, $messages);
    $mail->build();

    expect($mail->url)->toBe('https://example.com/receiver/'.$this->receiver->id);
});

it('url is null when no resolver is configured', function () {
    config()->set('messenger.mail.bulk_mail_url');

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
    ]);

    $mail = new BulkMail($this->receiver, $messages);
    $mail->build();

    expect($mail->url)->toBeNull();
});

it('uses the configured view', function () {
    config()->set('messenger.mail.bulk_mail_view', 'messenger::bulkMail');

    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
    ]);

    $mail = new BulkMail($this->receiver, $messages);
    $result = $mail->build();

    expect($result)->toBeInstanceOf(BulkMail::class);
});

it('exposes message group as public property', function () {
    $messages = collect([
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => createMessagable()->id,
        ]),
    ]);

    $mail = new BulkMail($this->receiver, $messages);

    expect($mail->messages)->toBe($messages)
        ->and($mail->messages)->toHaveCount(1);
});
