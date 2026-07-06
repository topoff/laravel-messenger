<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageFallbackCreatedEvent;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Services\ConsentService;
use Topoff\Messenger\Services\FallbackEngine;
use Workbench\App\Models\TestReceiver;

function fallbackPair(int $afterMinutes = 30): array
{
    $smsType = createMessageType(['channel' => 'vonage']);
    $mailType = createMessageType([
        'fallback_message_type_id' => $smsType->id,
        'fallback_after_minutes' => $afterMinutes,
    ]);

    return [$mailType, $smsType];
}

it('creates a follow-up on another channel when delivery confirmation times out (v9)', function () {
    Event::fake([MessageFallbackCreatedEvent::class]);
    [$mailType, $smsType] = fallbackPair();
    $receiver = createReceiver();

    $original = createMessage([
        'message_type_id' => $mailType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'params' => ['token' => 'abc'],
        'locale' => 'de',
        'sent_at' => Carbon::now()->subMinutes(31),
    ]);

    expect(app(FallbackEngine::class)->run())->toBe(1);

    $followUp = Message::where('fallback_of_message_id', $original->id)->sole();
    expect($followUp->message_type_id)->toBe($smsType->id)
        ->and($followUp->receiver_id)->toBe($original->receiver_id)
        ->and($followUp->params)->toBe(['token' => 'abc'])
        ->and($followUp->locale)->toBe('de')
        ->and($followUp->sent_at)->toBeNull();

    Event::assertDispatched(MessageFallbackCreatedEvent::class);

    // Idempotent: a second run creates nothing.
    expect(app(FallbackEngine::class)->run())->toBe(0);
});

it('does not fall back for delivered or fresh messages (v9)', function () {
    [$mailType] = fallbackPair();
    $receiver = createReceiver();

    createMessage([
        'message_type_id' => $mailType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'sent_at' => Carbon::now()->subMinutes(90),
        'delivered_at' => Carbon::now()->subMinutes(80),
    ]);
    createMessage([
        'message_type_id' => $mailType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'sent_at' => Carbon::now()->subMinutes(5),
    ]);

    expect(app(FallbackEngine::class)->run())->toBe(0);
});

it('falls back immediately on bounce, before the timeout (v9)', function () {
    [$mailType] = fallbackPair(60);
    $receiver = createReceiver();

    createMessage([
        'message_type_id' => $mailType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'sent_at' => Carbon::now()->subMinutes(2),
        'bounced_at' => Carbon::now()->subMinute(),
    ]);

    expect(app(FallbackEngine::class)->run())->toBe(1);
});

it('never loops between two types that fall back to each other (v9)', function () {
    [$mailType, $smsType] = fallbackPair();
    $smsType->update(['fallback_message_type_id' => $mailType->id, 'fallback_after_minutes' => 30]);
    $receiver = createReceiver();

    $original = createMessage([
        'message_type_id' => $mailType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'sent_at' => Carbon::now()->subMinutes(31),
    ]);

    expect(app(FallbackEngine::class)->run())->toBe(1);

    // Follow-up (sms) also times out — but falling back to mail again
    // would revisit the chain: blocked.
    Message::where('fallback_of_message_id', $original->id)
        ->update(['sent_at' => Carbon::now()->subMinutes(31)]);

    expect(app(FallbackEngine::class)->run())->toBe(0);
});

it('respects the consent guard for marketing fallbacks (v9)', function () {
    [$mailType, $smsType] = fallbackPair();
    $smsType->update(['message_class' => MessageType::CLASS_MARKETING]);
    $receiver = createReceiver();

    app(ConsentService::class)->optOut(TestReceiver::class, $receiver->id);

    createMessage([
        'message_type_id' => $mailType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'sent_at' => Carbon::now()->subMinutes(31),
    ]);

    expect(app(FallbackEngine::class)->run())->toBe(0);
});
