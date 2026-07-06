<?php

use Illuminate\Support\Carbon;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Services\SendRateGuard;
use Workbench\App\Models\TestReceiver;

function vonageType(): Topoff\Messenger\Models\MessageType
{
    return createMessageType(['channel' => 'vonage']);
}

it('defers over the per-receiver daily cap and after too-short intervals (v9)', function () {
    config()->set('messenger.rate_limit.channels.vonage', [
        'min_interval_seconds' => 60,
        'per_receiver_per_day' => 2,
    ]);

    $type = vonageType();
    $receiver = createReceiver();
    $base = ['message_type_id' => $type->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiver->id];

    // Fresh receiver: goes through.
    $first = createMessage($base);
    expect(app(SendRateGuard::class)->defers($first))->toBeFalse();

    // Sent 10 s ago -> min interval defers to lastSent + 60 s.
    createMessage($base + ['sent_at' => Carbon::now()->subSeconds(10)]);
    $second = createMessage($base);
    expect(app(SendRateGuard::class)->defers($second))->toBeTrue()
        ->and($second->scheduled_at->isFuture())->toBeTrue()
        ->and($second->reserved_at)->toBeNull();

    // Two sent today -> daily cap defers to tomorrow.
    createMessage($base + ['sent_at' => Carbon::now()->subHours(2)]);
    $third = createMessage($base);
    expect(app(SendRateGuard::class)->defers($third))->toBeTrue()
        ->and($third->scheduled_at->isTomorrow())->toBeTrue();
});

it('defers at the global channel cap, leaves other channels alone (v9)', function () {
    config()->set('messenger.rate_limit.channels.vonage', ['per_channel_per_day' => 1]);

    $type = vonageType();
    $mailType = createMessageType(['channel' => 'email', 'notification_class' => 'OtherMail']);
    $receiverA = createReceiver();
    $receiverB = createReceiver(['email' => 'b@example.com']);

    createMessage(['message_type_id' => $type->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiverA->id, 'sent_at' => Carbon::now()->subHour()]);

    $sms = createMessage(['message_type_id' => $type->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiverB->id]);
    expect(app(SendRateGuard::class)->defers($sms))->toBeTrue();

    // E-mail channel has no limits configured: untouched.
    $mail = createMessage(['message_type_id' => $mailType->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiverB->id]);
    expect(app(SendRateGuard::class)->defers($mail))->toBeFalse();
});

it('resolves params at send time through the configured resolver (v9)', function () {
    config()->set('messenger.rendering.resolve_params', function (Message $message): array {
        return array_merge($message->params ?? [], ['link' => 'https://example.com/l/short-'.$message->id]);
    });

    $type = createMessageType();
    $receiver = createReceiver();
    $message = createMessage([
        'message_type_id' => $type->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'params' => ['subject' => 'Hi'],
    ]);

    $handlerClass = $type->single_handler;
    (new $handlerClass($message))->send();

    $fresh = $message->fresh();
    expect($fresh->sent_at)->not->toBeNull()
        ->and($fresh->params['link'])->toBe('https://example.com/l/short-'.$message->id)
        ->and($fresh->params['subject'])->toBe('Hi');
});
