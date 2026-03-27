<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Date;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Services\MessageService;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;
use Workbench\App\Models\TestSender;

beforeEach(function () {
    $this->messageType = createMessageType();
    $this->receiver = createReceiver();
    $this->sender = createSender();
    $this->messagable = createMessagable();
});

it('creates a message with all required fields', function () {
    $service = new MessageService;

    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setSender(TestSender::class, $this->sender->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setCompanyId(1)
        ->create();

    expect(Message::count())->toBe(1);

    $message = Message::first();
    expect($message->receiver_type)->toBe(TestReceiver::class)
        ->and($message->receiver_id)->toBe($this->receiver->id)
        ->and($message->sender_type)->toBe(TestSender::class)
        ->and($message->sender_id)->toBe($this->sender->id)
        ->and($message->messagable_type)->toBe(TestMessagable::class)
        ->and($message->messagable_id)->toBe($this->messagable->id)
        ->and($message->company_id)->toBe(1)
        ->and($message->message_type_id)->toBe($this->messageType->id);
});

it('creates a message with scheduled_at', function () {
    Date::setTestNow('2025-06-15 10:00:00');
    $scheduled = Carbon::parse('2025-06-16 08:00:00');

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setScheduled($scheduled)
        ->create();

    expect(Message::first()->scheduled_at->toDateTimeString())->toBe('2025-06-16 08:00:00');
});

it('creates a message with params', function () {
    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setParams(['foo' => 'bar', 'baz' => 123])
        ->create();

    $message = Message::first();
    expect($message->params)->toBe(['foo' => 'bar', 'baz' => 123]);
});

it('creates a message with mail text', function () {
    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setMailText('Hello World')
        ->create();

    expect(data_get(Message::first()->params, 'text'))->toBe('Hello World');
});

it('resets vars after create for reuse', function () {
    $service = new MessageService;

    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->create();

    $receiver2 = createReceiver(['email' => 'second@example.com']);
    $service->setReceiver(TestReceiver::class, $receiver2->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->create();

    expect(Message::count())->toBe(2);
});

it('supports fluent chaining', function () {
    $service = new MessageService;

    $result = $service->setReceiver(TestReceiver::class, $this->receiver->id);
    expect($result)->toBeInstanceOf(MessageService::class);

    $result = $service->setSender(TestSender::class, $this->sender->id);
    expect($result)->toBeInstanceOf(MessageService::class);

    $result = $service->setMessagable(TestMessagable::class, $this->messagable->id);
    expect($result)->toBeInstanceOf(MessageService::class);

    $result = $service->setCompanyId(1);
    expect($result)->toBeInstanceOf(MessageService::class);

    $result = $service->setScheduled(Carbon::now());
    expect($result)->toBeInstanceOf(MessageService::class);

    $result = $service->setMailText('text');
    expect($result)->toBeInstanceOf(MessageService::class);

    $result = $service->setParams(['key' => 'val']);
    expect($result)->toBeInstanceOf(MessageService::class);
});

it('prevents message creation when prevent_create_message returns true', function () {
    config()->set('messenger.sending.prevent_create_message', fn () => true);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->create();

    expect(Message::count())->toBe(0);
});

it('creates message when prevent_create_message returns false', function () {
    config()->set('messenger.sending.prevent_create_message', fn () => false);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->create();

    expect(Message::count())->toBe(1);
});

it('creates message when prevent_create_message is null', function () {
    config()->set('messenger.sending.prevent_create_message');

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->create();

    expect(Message::count())->toBe(1);
});

it('prevent_create_message receives receiver class and id', function () {
    $receivedArgs = [];
    config()->set('messenger.sending.prevent_create_message', function (string $class, int $id) use (&$receivedArgs) {
        $receivedArgs = ['class' => $class, 'id' => $id];

        return false;
    });

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->create();

    expect($receivedArgs['class'])->toBe(TestReceiver::class)
        ->and($receivedArgs['id'])->toBe($this->receiver->id);
});

it('changes the schedule of an existing message', function () {
    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setCompanyId(1)
        ->create();

    $newSchedule = Carbon::parse('2025-12-25 08:00:00');
    $changed = $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setCompanyId(1)
        ->setScheduled($newSchedule)
        ->change();

    expect($changed)->not->toBeNull()
        ->and($changed->scheduled_at->toDateTimeString())->toBe('2025-12-25 08:00:00');
});

it('returns null when changing a non-existent message', function () {
    $service = new MessageService;
    $result = $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->setCompanyId(1)
        ->change();

    expect($result)->toBeNull();
});

it('deletes a message', function () {
    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->delete();

    // delete() creates and then soft-deletes
    expect(Message::count())->toBe(0)
        ->and(Message::withTrashed()->count())->toBe(1);
});

it('blocks creation when required_messagable is missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresMsgable',
        'required_messagable' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessageTypeClass('App\\Mail\\RequiresMsgable')
        ->create();

    expect(Message::count())->toBe(0);
});

it('blocks creation when required_sender is missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresSender',
        'required_sender' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresSender')
        ->create();

    expect(Message::count())->toBe(0);
});

it('blocks creation when required_company_id is missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresCompany',
        'required_company_id' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresCompany')
        ->create();

    expect(Message::count())->toBe(0);
});

it('blocks creation when required_text is missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresText',
        'required_text' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresText')
        ->create();

    expect(Message::count())->toBe(0);
});

it('blocks creation when required_scheduled is missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresScheduled',
        'required_scheduled' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresScheduled')
        ->create();

    expect(Message::count())->toBe(0);
});

it('blocks creation when required_params is missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresParams',
        'required_params' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresParams')
        ->create();

    expect(Message::count())->toBe(0);
});

it('createAndSendNow creates message and dispatches handler', function () {
    Illuminate\Support\Facades\Mail::fake();

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass(\Workbench\App\Mail\TestMail::class)
        ->createAndSendNow();

    expect(Message::count())->toBe(1);

    $message = Message::first();
    expect($message->scheduled_at)->toBeNull();
});

it('createAndSendNow blocks creation when required fields are missing', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresSenderNow',
        'required_sender' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresSenderNow')
        ->createAndSendNow();

    expect(Message::count())->toBe(0);
});

it('passes validation when all required fields are provided', function () {
    createMessageType([
        'notification_class' => 'App\\Mail\\RequiresAll',
        'required_sender' => true,
        'required_messagable' => true,
        'required_company_id' => true,
        'required_text' => true,
        'required_scheduled' => true,
        'required_params' => true,
    ]);

    $service = new MessageService;
    $service->setReceiver(TestReceiver::class, $this->receiver->id)
        ->setSender(TestSender::class, $this->sender->id)
        ->setMessagable(TestMessagable::class, $this->messagable->id)
        ->setMessageTypeClass('App\\Mail\\RequiresAll')
        ->setCompanyId(1)
        ->setScheduled(Carbon::now())
        ->setParams(['key' => 'value', 'text' => 'Hello'])
        ->create();

    expect(Message::count())->toBe(1);
});
