<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Events\MessageRejectedEvent;
use Topoff\Messenger\Events\SmsDeliveryFailedEvent;
use Topoff\Messenger\Jobs\RecordAwsSmsEventJob;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function awsSmsEvent(array $overrides = []): array
{
    return array_merge([
        'eventType' => 'TEXT_DELIVERED',
        'isFinal' => true,
        'messageId' => 'aws-msg-1',
        'eventTimestamp' => now()->getTimestampMs(),
        'messageStatusDescription' => 'Message has been accepted by phone',
        'carrierName' => 'Swisscom AG',
        'mcc' => '228',
        'mnc' => '01',
        'totalMessagePrice' => 0.05124,
        'totalMessageParts' => 1,
    ], $overrides);
}

function runAwsSmsEventJob(array $event, ?QueueJob $queueJob = null): RecordAwsSmsEventJob
{
    $job = new RecordAwsSmsEventJob($event);
    if ($queueJob instanceof Job) {
        $job->setJob($queueJob);
    }
    $job->handle(app(SmsDeliveryRecorder::class));

    return $job;
}

function fakeQueueJob(int $attempts): QueueJob
{
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);
    $queueJob->shouldReceive('isReleased')->andReturnFalse();
    $queueJob->shouldReceive('release')->byDefault();

    return $queueJob;
}

it('records a final delivery by provider message id and fires the event once', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = createMessage(['tracking_message_id' => 'aws-msg-1', 'tracking_meta' => ['provider' => 'aws']]);

    runAwsSmsEventJob(awsSmsEvent());
    runAwsSmsEventJob(awsSmsEvent());

    $message->refresh();
    expect($message->delivered_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and(data_get($message->tracking_meta, 'aws_sms_status'))->toBe('TEXT_DELIVERED')
        ->and(data_get($message->tracking_meta, 'aws_sms_carrier'))->toBe('Swisscom AG')
        ->and(data_get($message->tracking_meta, 'aws_sms_mcc_mnc'))->toBe('228/01')
        ->and(data_get($message->tracking_meta, 'provider'))->toBe('aws')
        ->and(data_get($message->tracking_meta, SmsDeliveryRecorder::PENDING_KEY))->toBeNull()
        ->and(data_get($message->tracking_meta, 'late_events'))->toBeNull();

    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
});

it('treats a final TEXT_SUCCESSFUL as delivered', function () {
    $message = createMessage(['tracking_message_id' => 'aws-msg-1']);

    runAwsSmsEventJob(awsSmsEvent(['eventType' => 'TEXT_SUCCESSFUL']));

    expect($message->refresh()->delivered_at)->not->toBeNull();
});

it('matches by the messenger id from the context when the provider id was never stored', function () {
    $message = createMessage();

    runAwsSmsEventJob(awsSmsEvent(['messageId' => 'never-stored', 'context' => ['messenger_message_id' => (string) $message->id]]));

    expect($message->refresh()->delivered_at)->not->toBeNull();
});

it('matches an earlier provider id kept in previous_message_ids', function () {
    $message = createMessage(['tracking_message_id' => 'aws-msg-2', 'tracking_meta' => ['previous_message_ids' => ['aws-msg-1']]]);

    runAwsSmsEventJob(awsSmsEvent());

    expect($message->refresh()->delivered_at)->not->toBeNull();
});

it('ignores non-final events', function () {
    Event::fake([MessageDeliveredEvent::class, SmsDeliveryFailedEvent::class]);
    $message = createMessage(['tracking_message_id' => 'aws-msg-1']);

    runAwsSmsEventJob(awsSmsEvent(['eventType' => 'TEXT_PENDING', 'isFinal' => false]));

    expect($message->refresh()->delivered_at)->toBeNull();
    Event::assertNotDispatched(MessageDeliveredEvent::class);
    Event::assertNotDispatched(SmsDeliveryFailedEvent::class);
});

it('records each failure type as failed and fires only SmsDeliveryFailedEvent', function (string $eventType) {
    Event::fake([SmsDeliveryFailedEvent::class, MessageRejectedEvent::class]);
    $message = createMessage(['tracking_message_id' => 'aws-msg-1']);

    runAwsSmsEventJob(awsSmsEvent(['eventType' => $eventType]));

    expect($message->refresh()->failed_at)->not->toBeNull()
        ->and($message->delivered_at)->toBeNull();
    Event::assertDispatched(SmsDeliveryFailedEvent::class, fn (SmsDeliveryFailedEvent $e): bool => $e->status === $eventType && $e->provider === 'aws' && $e->message->id === $message->id);
    Event::assertNotDispatched(MessageRejectedEvent::class);
})->with(RecordAwsSmsEventJob::FAILED_EVENTS);

it('keeps the first final state and only notes a contradicting later one', function () {
    Event::fake([MessageDeliveredEvent::class, SmsDeliveryFailedEvent::class]);
    $message = createMessage(['tracking_message_id' => 'aws-msg-1']);

    runAwsSmsEventJob(awsSmsEvent());
    runAwsSmsEventJob(awsSmsEvent(['eventType' => 'TEXT_UNREACHABLE']));

    $message->refresh();
    expect($message->delivered_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and(data_get($message->tracking_meta, 'late_events.0.status'))->toBe('TEXT_UNREACHABLE');
    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
    Event::assertNotDispatched(SmsDeliveryFailedEvent::class);
});

it('creates late_events when tracking_meta is empty', function () {
    $message = createMessage(['tracking_message_id' => 'aws-msg-1', 'tracking_meta' => null, 'failed_at' => now()]);

    runAwsSmsEventJob(awsSmsEvent());

    expect(data_get($message->refresh()->tracking_meta, 'late_events.0.status'))->toBe('TEXT_DELIVERED');
});

it('retries an event that overtook the send listener', function () {
    $queueJob = fakeQueueJob(1);
    $queueJob->shouldReceive('release')->once()->with(30);

    runAwsSmsEventJob(awsSmsEvent(['messageId' => 'not-yet-stored']), $queueJob);
});

it('drops an old event it cannot match', function () {
    $queueJob = fakeQueueJob(1);
    $queueJob->shouldNotReceive('release');

    runAwsSmsEventJob(awsSmsEvent(['messageId' => 'test-sms', 'eventTimestamp' => now()->subHour()->getTimestampMs()]), $queueJob);
});

it('records a matched event regardless of its age', function () {
    $message = createMessage(['tracking_message_id' => 'aws-msg-1']);

    runAwsSmsEventJob(awsSmsEvent(['eventTimestamp' => now()->subDay()->getTimestampMs()]));

    expect($message->refresh()->delivered_at)->not->toBeNull();
});

it('fires the event again on retry when a listener failed after the final update', function () {
    $message = createMessage(['tracking_message_id' => 'aws-msg-1']);
    $calls = 0;
    Event::listen(MessageDeliveredEvent::class, function () use (&$calls): void {
        $calls++;
        if ($calls === 1) {
            throw new RuntimeException('listener crashed');
        }
    });

    expect(fn () => runAwsSmsEventJob(awsSmsEvent()))->toThrow(RuntimeException::class);
    expect(data_get($message->refresh()->tracking_meta, SmsDeliveryRecorder::PENDING_KEY.'.status'))->toBe('TEXT_DELIVERED');

    runAwsSmsEventJob(awsSmsEvent());

    expect($calls)->toBe(2)
        ->and(data_get($message->refresh()->tracking_meta, SmsDeliveryRecorder::PENDING_KEY))->toBeNull()
        ->and(data_get($message->tracking_meta, 'sms_final_status'))->toBe('TEXT_DELIVERED');
});

it('lets only one of two concurrent copies dispatch the event', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = createMessage(['tracking_message_id' => 'aws-msg-1', 'delivered_at' => now(), 'tracking_meta' => [
        SmsDeliveryRecorder::PENDING_KEY => ['provider' => 'aws', 'status' => 'TEXT_DELIVERED', 'delivered' => true, 'claimed_at' => now()->toIso8601String()],
    ]]);

    // Another worker holds a fresh claim: this copy must not dispatch.
    runAwsSmsEventJob(awsSmsEvent());
    Event::assertNotDispatched(MessageDeliveredEvent::class);

    // An abandoned claim (worker died) is taken over.
    $meta = $message->refresh()->tracking_meta;
    $meta[SmsDeliveryRecorder::PENDING_KEY]['claimed_at'] = now()->subMinutes(SmsDeliveryRecorder::CLAIM_TTL_MINUTES + 1)->toIso8601String();
    $message->forceFill(['tracking_meta' => $meta])->save();

    runAwsSmsEventJob(awsSmsEvent());
    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
    expect(data_get($message->refresh()->tracking_meta, SmsDeliveryRecorder::PENDING_KEY))->toBeNull();
});
