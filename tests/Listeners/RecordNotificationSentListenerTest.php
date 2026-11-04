<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Channels\AwsSmsSentMessage;
use Topoff\Messenger\Listeners\RecordNotificationSentListener;

beforeEach(function () {
    $this->listener = new RecordNotificationSentListener;
});

it('records vonage response on messenger notification', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notifiable->route('vonage', '+41791234567');

    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $sentSms = new class
    {
        public function getMessageId(): string
        {
            return 'vonage-msg-001';
        }

        public function getTo(): string
        {
            return '+41791234567';
        }

        public function getStatus(): int
        {
            return 0;
        }

        public function getNetwork(): string
        {
            return '22801';
        }

        public function getPrice(): string
        {
            return '0.0354';
        }
    };

    $response = new readonly class($sentSms) implements IteratorAggregate
    {
        public function __construct(private object $item) {}

        public function getIterator(): ArrayIterator
        {
            return new ArrayIterator([$this->item]);
        }
    };

    $event = new NotificationSent($notifiable, $notification, 'vonage', $response);

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBe('vonage-msg-001')
        ->and($message->tracking_recipient_contact)->toBe('+41791234567')
        ->and(data_get($message->tracking_meta, 'vonage_status'))->toBe('0')
        ->and(data_get($message->tracking_meta, 'vonage_network'))->toBe('22801')
        ->and(data_get($message->tracking_meta, 'vonage_message_price'))->toBe('0.0354');
});

it('skips non-vonage channels', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $event = new NotificationSent($notifiable, $notification, 'mail');

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBeNull();
});

it('skips notifications without messengerMessageId', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};

    $event = new NotificationSent($notifiable, $notification, 'vonage');

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBeNull();
});

it('skips when response is not traversable', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $event = new NotificationSent($notifiable, $notification, 'vonage', 'plain-string-response');

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBeNull();
});

function sentSmsResponse(string $messageId, string $to = '+41791234567'): object
{
    return new AwsSmsSentMessage($messageId, $to);
}

it('records the aws-sms channel with provider aws', function () {
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'aws-sms', sentSmsResponse('aws-001')));

    $message->refresh();
    expect($message->tracking_message_id)->toBe('aws-001')
        ->and($message->tracking_recipient_contact)->toBe('+41791234567')
        ->and(data_get($message->tracking_meta, 'provider'))->toBe('aws');
});

it('keeps tracking vonage when the host config has no sms_channels key', function () {
    config()->set('messenger.tracking', ['route' => ['prefix' => 'email']]);
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'vonage', sentSmsResponse('vonage-xyz')));

    expect($message->refresh()->tracking_message_id)->toBe('vonage-xyz');
});

it('does not track channels outside sms_channels', function () {
    config()->set('messenger.tracking.sms_channels', ['vonage']);
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'aws-sms', sentSmsResponse('aws-001')));

    expect($message->refresh()->tracking_message_id)->toBeNull();
});

it('keeps the provider id of an earlier send of the same message', function () {
    $message = createMessage(['tracking_message_id' => 'aws-first', 'tracking_meta' => ['aws_sms_status' => 'TEXT_PENDING']]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'aws-sms', sentSmsResponse('aws-second')));

    $message->refresh();
    expect($message->tracking_message_id)->toBe('aws-second')
        ->and(data_get($message->tracking_meta, 'previous_message_ids'))->toBe(['aws-first'])
        ->and(data_get($message->tracking_meta, 'aws_sms_status'))->toBe('TEXT_PENDING');
});

function vonagePart(string $messageId, string $clientRef = ''): object
{
    return new readonly class($messageId, $clientRef)
    {
        public function __construct(private string $messageId, private string $clientRef) {}

        public function getMessageId(): string
        {
            return $this->messageId;
        }

        public function getClientRef(): string
        {
            return $this->clientRef;
        }

        public function getTo(): string
        {
            return '+41791234567';
        }
    };
}

function vonageParts(object ...$parts): object
{
    return new readonly class($parts) implements IteratorAggregate
    {
        /** @param  list<object>  $parts */
        public function __construct(private array $parts) {}

        public function getIterator(): ArrayIterator
        {
            return new ArrayIterator($this->parts);
        }
    };
}

it('stores all part ids of a multipart sms sent with the messenger client reference', function () {
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;
    $reference = 'msg:'.$message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'vonage', vonageParts(vonagePart('part-1', $reference), vonagePart('part-2', $reference))));

    $message->refresh();
    expect($message->tracking_message_id)->toBe('part-1')
        ->and(data_get($message->tracking_meta, 'sms_part_ids'))->toBe(['part-1', 'part-2']);
});

it('stores no part ids for a multipart sms without the messenger client reference', function () {
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'vonage', vonageParts(vonagePart('part-1', 'host-ref'), vonagePart('part-2', 'host-ref'))));

    $message->refresh();
    expect($message->tracking_message_id)->toBe('part-1')
        ->and(data_get($message->tracking_meta, 'sms_part_ids'))->toBeNull();
});

it('stores no part ids for a single-part sms', function () {
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'vonage', vonageParts(vonagePart('only-part', 'msg:'.$message->id))));

    expect(data_get($message->refresh()->tracking_meta, 'sms_part_ids'))->toBeNull();
});

it('moves the part ids of an earlier send and clears their part statuses on a new send', function () {
    $message = createMessage([
        'tracking_message_id' => 'old-1',
        'tracking_meta' => ['sms_part_ids' => ['old-1', 'old-2'], 'sms_part_statuses' => ['old-1' => 'delivered']],
    ]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;
    $reference = 'msg:'.$message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'vonage', vonageParts(vonagePart('new-1', $reference), vonagePart('new-2', $reference))));

    $meta = $message->refresh()->tracking_meta;
    expect(data_get($meta, 'sms_part_ids'))->toBe(['new-1', 'new-2'])
        ->and(data_get($meta, 'previous_sms_part_ids'))->toBe(['old-1', 'old-2'])
        ->and(data_get($meta, 'previous_message_ids'))->toBe(['old-1'])
        ->and(data_get($meta, 'sms_part_statuses'))->toBeNull();
});

it('logs a warning, not an error, when the channel sent nothing', function () {
    Log::spy();
    $message = createMessage(['tracking_meta' => []]);
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $this->listener->handle(new NotificationSent(new AnonymousNotifiable, $notification, 'vonage'));

    expect($message->refresh()->tracking_message_id)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $text): bool => str_contains($text, 'no SMS was sent'));
    Log::shouldNotHaveReceived('error');
});
