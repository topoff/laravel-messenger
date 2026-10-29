<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\MessengerServiceProvider;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

function messageWithPendingDelivery(int $minutesAgo): Message
{
    $message = createMessage(['delivered_at' => now(), 'tracking_meta' => [
        SmsDeliveryRecorder::PENDING_KEY => ['provider' => 'aws', 'status' => 'TEXT_DELIVERED', 'delivered' => true],
    ]]);
    $message->newQuery()->whereKey($message->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

    return $message;
}

it('dispatches a stuck pending event once and clears the marker', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = messageWithPendingDelivery(30);

    $this->artisan('messenger:dispatch-pending-sms-events')->assertSuccessful();
    $this->artisan('messenger:dispatch-pending-sms-events')->assertSuccessful();

    Event::assertDispatchedTimes(MessageDeliveredEvent::class, 1);
    expect(data_get($message->refresh()->tracking_meta, SmsDeliveryRecorder::PENDING_KEY))->toBeNull();
});

it('leaves a young marker to the delivery job', function () {
    Event::fake([MessageDeliveredEvent::class]);
    $message = messageWithPendingDelivery(2);

    $this->artisan('messenger:dispatch-pending-sms-events')->assertSuccessful();

    Event::assertNotDispatched(MessageDeliveredEvent::class);
    expect(data_get($message->refresh()->tracking_meta, SmsDeliveryRecorder::PENDING_KEY))->not->toBeNull();
});

function pendingSweepEvents(): Collection
{
    (fn () => $this->registerPendingSmsEventsSchedule())->call(app()->getProvider(MessengerServiceProvider::class));
    app()->forgetInstance(Schedule::class);
    $schedule = app(Schedule::class);

    return collect($schedule->events())->filter(fn ($event): bool => $event->description === 'messenger.tracking.pending-sms-events')->values();
}

it('is scheduled while aws sms events are enabled, in UTC, skipping the maintenance window', function () {
    config()->set('messenger.tracking.aws_sms_events', ['enabled' => true, 'topic_arn' => 'arn', 'pending_sweep' => ['cron' => '*/10 * * * *', 'timezone' => 'UTC', 'skip_between' => ['01:00', '02:45']]]);

    $events = pendingSweepEvents();

    expect($events)->not->toBeEmpty()
        ->and($events->first()->expression)->toBe('*/10 * * * *')
        ->and($events->first()->timezone)->toBe('UTC');

    // The scheduler rebuilds the schedule every run; the window uses the time of registration.
    $this->travelTo(Carbon::parse('2026-10-29 01:30:00', 'UTC'));
    expect(pendingSweepEvents()->every(fn ($event): bool => ! $event->filtersPass(app())))->toBeTrue();
    $this->travelTo(Carbon::parse('2026-10-29 03:00:00', 'UTC'));
    expect(pendingSweepEvents()->every(fn ($event): bool => $event->filtersPass(app())))->toBeTrue();
});

it('is not scheduled while aws sms events are disabled', function () {
    config()->set('messenger.tracking.aws_sms_events.enabled', false);

    expect(pendingSweepEvents())->toBeEmpty();
});

it('is also scheduled for hosts that only enabled vonage delivery receipts', function () {
    config()->set('messenger.tracking.aws_sms_events.enabled', false);
    config()->set('messenger.tracking.vonage_dlr', ['enabled' => true, 'token' => 't']);

    expect(pendingSweepEvents())->not->toBeEmpty();
});
