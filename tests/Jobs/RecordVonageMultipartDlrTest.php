<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Events\MessageRejectedEvent;
use Topoff\Messenger\Jobs\RecordVonageDlrJob;
use Topoff\Messenger\Notifications\Concerns\TracksSmsDelivery;
use Topoff\Messenger\Services\Sms\SmsClientReference;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

function multipartMessage(array $meta = []): object
{
    return createMessage([
        'tracking_message_id' => 'part-1',
        'tracking_meta' => array_merge(['sms_part_ids' => ['part-1', 'part-2']], $meta),
    ]);
}

function vonageDlr(object $message, string $partId, string $status): RecordVonageDlrJob
{
    return new RecordVonageDlrJob(['messageId' => $partId, 'status' => $status, 'client-ref' => 'msg:'.$message->id]);
}

it('finds a later part by the messenger client reference', function () {
    $message = multipartMessage();

    vonageDlr($message, 'part-2', 'accepted')->handle();

    expect(data_get($message->refresh()->tracking_meta, 'sms_part_statuses'))->toBe(['part-2' => 'accepted']);
});

it('counts a multipart sms as delivered only once every part was delivered', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = multipartMessage();

    vonageDlr($message, 'part-1', 'delivered')->handle();
    expect($message->refresh()->delivered_at)->toBeNull();
    Event::assertNotDispatched(MessageDeliveredEvent::class);

    vonageDlr($message, 'part-2', 'delivered')->handle();
    expect($message->refresh()->delivered_at)->not->toBeNull();
    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
});

it('fails a multipart sms when a later failed part follows a delivered one', function () {
    Event::fake([MessageDeliveredEvent::class, MessageRejectedEvent::class]);
    $message = multipartMessage();

    vonageDlr($message, 'part-2', 'delivered')->handle();
    vonageDlr($message, 'part-1', 'failed')->handle();

    $message->refresh();
    expect($message->failed_at)->not->toBeNull()
        ->and($message->delivered_at)->toBeNull();
    Event::assertNotDispatched(MessageDeliveredEvent::class);
    Event::assertDispatched(MessageRejectedEvent::class);
});

it('keeps a receipt of an earlier send only as late event', function () {
    Event::fake([MessageRejectedEvent::class]);
    $message = multipartMessage(['previous_sms_part_ids' => ['old-2']]);

    vonageDlr($message, 'old-2', 'failed')->handle();

    $message->refresh();
    expect($message->failed_at)->toBeNull()
        ->and(data_get($message->tracking_meta, 'late_events.0.provider_message_id'))->toBe('old-2');
    Event::assertNotDispatched(MessageRejectedEvent::class);
});

it('does not finalize on a receipt for an earlier primary id', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = createMessage(['tracking_message_id' => 'second', 'tracking_meta' => ['previous_message_ids' => ['first']]]);

    new RecordVonageDlrJob(['messageId' => 'first', 'status' => 'delivered'])->handle();

    expect($message->refresh()->delivered_at)->toBeNull();
    Event::assertNotDispatched(MessageDeliveredEvent::class);
});

it('does not write an unverified receipt that never matched a send', function () {
    $message = createMessage(['tracking_meta' => []]);

    vonageDlr($message, 'not-yet-stored', 'delivered')->handle();

    $message->refresh();
    expect($message->delivered_at)->toBeNull()
        ->and($message->tracking_meta)->toBe([]);
});

it('keeps a delivered part when a late intermediate receipt of it follows', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = multipartMessage();

    vonageDlr($message, 'part-1', 'delivered')->handle();
    vonageDlr($message, 'part-1', 'accepted')->handle();
    vonageDlr($message, 'part-2', 'delivered')->handle();

    expect($message->refresh()->delivered_at)->not->toBeNull();
    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
});

it('keeps the final state when a new send was recorded after the receipt was classified', function () {
    Event::fake([MessageRejectedEvent::class]);
    $message = multipartMessage();

    app(SmsDeliveryRecorder::class)->recordFinal($message, 'vonage', 'failed', false, now(), [], fn (): bool => false, 'part-1');

    $message->refresh();
    expect($message->failed_at)->toBeNull()
        ->and(data_get($message->tracking_meta, 'late_events.0.provider_message_id'))->toBe('part-1');
    Event::assertNotDispatched(MessageRejectedEvent::class);
});

it('records each late receipt of an earlier send only once', function () {
    $message = multipartMessage(['previous_sms_part_ids' => ['old-2']]);

    vonageDlr($message, 'old-2', 'failed')->handle();
    vonageDlr($message, 'old-2', 'failed')->handle();

    expect(data_get($message->refresh()->tracking_meta, 'late_events'))->toHaveCount(1);
});

it('releases a receipt that overtook the send listener while lookups remain', function () {
    $message = createMessage(['tracking_meta' => []]);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(30);

    $job = vonageDlr($message, 'not-yet-stored', 'delivered');
    $job->setJob($queueJob);
    $job->handle();

    expect(data_get($message->refresh()->tracking_meta, 'late_events'))->toBeNull();
});

it('ignores a host client reference and falls back to the provider id', function () {
    $other = createMessage(['tracking_meta' => []]);
    $message = createMessage(['tracking_message_id' => 'vonage-own', 'tracking_meta' => []]);

    new RecordVonageDlrJob(['messageId' => 'vonage-own', 'status' => 'delivered', 'client-ref' => (string) $other->id])->handle();

    expect($message->refresh()->delivered_at)->not->toBeNull()
        ->and($other->refresh()->tracking_meta)->toBe([]);
});

it('logs unmatched receipts without messenger reference as warning, with reference as error', function () {
    Log::spy();

    new RecordVonageDlrJob(['messageId' => 'unknown-1', 'status' => 'delivered'])->handle();
    new RecordVonageDlrJob(['messageId' => 'unknown-2', 'status' => 'delivered', 'client-ref' => 'msg:999999'])->handle();

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('error')->once();
});

it('builds the messenger client reference only for notifications with a messenger id', function () {
    $notification = new class
    {
        use TracksSmsDelivery;

        public ?int $messengerMessageId = null;

        public function reference(): string
        {
            return $this->messengerClientReference();
        }
    };

    expect($notification->reference())->toBe('');

    $notification->messengerMessageId = 42;
    expect($notification->reference())->toBe('msg:42')
        ->and(SmsClientReference::messengerMessageId('msg:42'))->toBe('42')
        ->and(SmsClientReference::messengerMessageId('42'))->toBeNull()
        ->and(SmsClientReference::messengerMessageId('kunde:7'))->toBeNull();
});

it('does not merge an intermediate receipt of a send replaced meanwhile', function () {
    $message = createMessage(['tracking_message_id' => 'second', 'tracking_meta' => ['previous_message_ids' => ['first']]]);

    $partState = app(SmsDeliveryRecorder::class)->recordPartStatus($message->id, 'first', 'accepted', ['dlr_status' => 'accepted']);

    expect($partState)->toBeNull()
        ->and(data_get($message->refresh()->tracking_meta, 'dlr_status'))->toBeNull();
});

it('merges intermediate receipt details of a single-part sms', function () {
    $message = createMessage(['tracking_message_id' => 'single', 'tracking_meta' => []]);

    new RecordVonageDlrJob(['messageId' => 'single', 'status' => 'accepted', 'err-code' => '0'])->handle();

    $message->refresh();
    expect(data_get($message->tracking_meta, 'dlr_status'))->toBe('accepted')
        ->and($message->delivered_at)->toBeNull();
});

it('replaces a failure once every part was delivered, even after a repeated failure receipt (delivery_overrides_failure)', function () {
    config(['messenger.sms.delivery_overrides_failure' => true]);
    Event::fake([MessageDeliveredEvent::class, MessageRejectedEvent::class]);
    $message = multipartMessage();

    vonageDlr($message, 'part-1', 'failed')->handle();
    vonageDlr($message, 'part-1', 'delivered')->handle();
    vonageDlr($message, 'part-1', 'failed')->handle();
    vonageDlr($message, 'part-2', 'delivered')->handle();

    $message->refresh();
    expect($message->delivered_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and(data_get($message->tracking_meta, 'sms_part_statuses'))->toBe(['part-1' => 'delivered', 'part-2' => 'delivered']);
    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
});
