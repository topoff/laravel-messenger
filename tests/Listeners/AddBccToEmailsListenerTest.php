<?php

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use Topoff\Messenger\Listeners\AddBccToEmailsListener;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;

beforeEach(function () {
    $this->listener = new AddBccToEmailsListener;
});

it('adds bcc when configured', function () {
    config()->set('mail.bcc.address', 'bcc@example.com');
    config()->set('messenger.bcc.check_should_add_bcc');

    $email = new Email;
    $email->to('to@example.com');

    $event = new MessageSending($email);

    $this->listener->handle($event);

    $bccAddresses = $email->getBcc();
    expect($bccAddresses)->toHaveCount(1)
        ->and($bccAddresses[0]->getAddress())->toBe('bcc@example.com');
});

it('does not add bcc when check_should_add_bcc returns false', function () {
    config()->set('mail.bcc.address', 'bcc@example.com');
    config()->set('messenger.bcc.check_should_add_bcc', fn () => false);

    $email = new Email;
    $email->to('to@example.com');

    $event = new MessageSending($email);

    $this->listener->handle($event);

    expect($email->getBcc())->toBeEmpty();
});

it('adds bcc when check_should_add_bcc returns true', function () {
    config()->set('mail.bcc.address', 'bcc@example.com');
    config()->set('messenger.bcc.check_should_add_bcc', fn () => true);

    $email = new Email;
    $email->to('to@example.com');

    $event = new MessageSending($email);

    $this->listener->handle($event);

    $bccAddresses = $email->getBcc();
    expect($bccAddresses)->toHaveCount(1)
        ->and($bccAddresses[0]->getAddress())->toBe('bcc@example.com');
});

it('does not add bcc when no bcc address is configured', function () {
    config()->set('mail.bcc.address');
    config()->set('messenger.bcc.check_should_add_bcc');

    $email = new Email;
    $email->to('to@example.com');

    $event = new MessageSending($email);

    $this->listener->handle($event);

    expect($email->getBcc())->toBeEmpty();
});

it('respects dev_bcc flag on message type when messageModel is present', function () {
    config()->set('mail.bcc.address', 'bcc@example.com');
    config()->set('messenger.bcc.check_should_add_bcc');

    $messageType = createMessageType(['dev_bcc' => false]);
    $messageModel = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => createReceiver()->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable()->id,
    ]);
    $messageModel->load('messageType');

    $email = new Email;
    $email->to('to@example.com');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);

    $this->listener->handle($event);

    expect($email->getBcc())->toBeEmpty();
});

it('adds bcc when dev_bcc is true on message type', function () {
    config()->set('mail.bcc.address', 'bcc@example.com');
    config()->set('messenger.bcc.check_should_add_bcc');

    $messageType = createMessageType(['dev_bcc' => true]);
    $messageModel = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => createReceiver()->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable()->id,
    ]);
    $messageModel->load('messageType');

    $email = new Email;
    $email->to('to@example.com');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);

    $this->listener->handle($event);

    $bccAddresses = $email->getBcc();
    expect($bccAddresses)->toHaveCount(1)
        ->and($bccAddresses[0]->getAddress())->toBe('bcc@example.com');
});
