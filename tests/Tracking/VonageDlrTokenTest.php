<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageRejectedEvent;
use Topoff\Messenger\Events\SmsDeliveryFailedEvent;
use Topoff\Messenger\Jobs\RecordVonageDlrJob;

beforeEach(function () {
    config()->set('messenger.tracking.vonage_dlr', ['enabled' => true, 'token' => 'secret-token']);
});

it('rejects a missing or wrong token', function (?string $token) {
    Bus::fake();

    $this->postJson(route('messenger.tracking.vonage-dlr', array_filter(['token' => $token])), ['messageId' => 'v-1', 'status' => 'delivered'])
        ->assertForbidden();

    Bus::assertNothingDispatched();
})->with(['missing' => [null], 'wrong' => ['nope']]);

it('accepts the right token and never passes it to the job', function () {
    Bus::fake();

    $this->postJson(route('messenger.tracking.vonage-dlr', ['token' => 'secret-token']), ['messageId' => 'v-1', 'status' => 'delivered'])
        ->assertOk();

    Bus::assertDispatched(RecordVonageDlrJob::class, fn (RecordVonageDlrJob $job): bool => $job->data === ['messageId' => 'v-1', 'status' => 'delivered']);
});

it('keeps the old open behaviour when no token is configured', function () {
    Bus::fake();
    config()->set('messenger.tracking.vonage_dlr.token');

    $this->postJson(route('messenger.tracking.vonage-dlr'), ['messageId' => 'v-1', 'status' => 'delivered'])->assertOk();

    Bus::assertDispatched(RecordVonageDlrJob::class);
});

it('fires MessageRejectedEvent and then SmsDeliveryFailedEvent for a failed dlr, even if the first listener throws', function () {
    $message = createMessage(['tracking_message_id' => 'v-2', 'tracking_meta' => []]);
    $order = [];
    Event::listen(MessageRejectedEvent::class, function () use (&$order): void {
        $order[] = 'rejected';
        throw new RuntimeException('host listener crashed');
    });
    Event::listen(SmsDeliveryFailedEvent::class, function (SmsDeliveryFailedEvent $e) use (&$order): void {
        $order[] = 'sms-failed:'.$e->provider;
    });

    expect(fn () => new RecordVonageDlrJob(['messageId' => 'v-2', 'status' => 'failed'])->handle())->toThrow(RuntimeException::class);

    expect($order)->toBe(['rejected', 'sms-failed:vonage'])
        ->and($message->refresh()->failed_at)->not->toBeNull();
});

it('retries a dlr that overtook the send listener', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(30);

    $job = new RecordVonageDlrJob(['messageId' => 'not-yet', 'status' => 'delivered']);
    $job->setJob($queueJob);
    $job->handle();
});
