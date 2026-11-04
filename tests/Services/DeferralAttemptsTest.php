<?php

/**
 * A deferral (rate guard or handler logic in shouldBeSentNow) is not a send
 * attempt (v8.12.1): backoff and max_retry_attempts only count real attempts.
 */

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Topoff\Messenger\Jobs\SendMessageJob;
use Topoff\Messenger\MailHandler\MainMailHandler;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\NotificationHandler\MainNotificationHandler;
use Topoff\Messenger\Notifications\NovaChannelNotification;
use Workbench\App\Mail\TestMail;
use Workbench\App\Models\TestReceiver;

class DeferralAttemptsThrowingHandler extends MainMailHandler
{
    #[Override]
    public function shouldBeSentNow(): bool
    {
        throw new RuntimeException('guard failed');
    }
}

beforeEach(function (): void {
    Mail::fake();
    Date::setTestNow('2025-06-15 12:00:00');
    config()->set('messenger.sending.check_should_send', fn (): bool => true);
    config()->set('messenger.rate_limit.channels.vonage', ['min_interval_seconds' => 60]);

    $this->receiver = createReceiver();
    $this->type = createMessageType(['channel' => 'vonage', 'max_retry_attempts' => 2]);
    $this->base = [
        'message_type_id' => $this->type->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
    ];
    $this->sentJustNow = fn (): Message => createMessage([...$this->base, 'sent_at' => now()]);
});

it('gives the attempt back when the rate guard defers a notification', function (): void {
    $type = createMessageType([
        'channel' => 'vonage',
        'notification_class' => NovaChannelNotification::class,
        'single_handler' => MainNotificationHandler::class,
    ]);
    $base = [...$this->base, 'message_type_id' => $type->id];
    createMessage([...$base, 'sent_at' => now()->subSeconds(10)]);
    $message = createMessage($base);

    new MainNotificationHandler($message->load('messageType'))->send();

    $message->refresh();
    expect($message->attempts)->toBe(0)
        ->and($message->sent_at)->toBeNull()
        ->and($message->reserved_at)->toBeNull()
        ->and($message->scheduled_at?->toDateTimeString())->toBe('2025-06-15 12:00:50');
});

it('gives the attempt back when the rate guard defers a mail', function (): void {
    createMessage([...$this->base, 'sent_at' => now()->subSeconds(10)]);
    $message = createMessage([...$this->base, 'attempts' => 3]);

    new MainMailHandler($message->load('messageType'))->send();

    $message->refresh();
    expect($message->attempts)->toBe(3)
        ->and($message->sent_at)->toBeNull()
        ->and($message->reserved_at)->toBeNull()
        ->and($message->scheduled_at)->not->toBeNull();
    Mail::assertNothingSent();
});

it('releases the reservation of a deferred mail, so the normal run picks it up when due', function (): void {
    createMessage([...$this->base, 'sent_at' => now()->subSeconds(10)]);
    $message = createMessage($this->base);

    (new SendMessageJob)->handle();
    expect($message->refresh()->reserved_at)->toBeNull()
        ->and($message->attempts)->toBe(0);

    Date::setTestNow(now()->addSeconds(60));
    (new SendMessageJob)->handle();

    $message->refresh();
    expect($message->sent_at)->not->toBeNull()
        ->and($message->attempts)->toBe(1);
});

it('retries a failed message after repeated deferrals within max_retry_attempts', function (): void {
    $message = createMessage([
        ...$this->base,
        'attempts' => 1,
        'error_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(30),
    ]);

    ($this->sentJustNow)();
    new SendMessageJob(true)->handle();
    expect($message->refresh()->attempts)->toBe(1)
        ->and($message->sent_at)->toBeNull();

    Date::setTestNow(now()->addSeconds(70));
    ($this->sentJustNow)();
    new SendMessageJob(true)->handle();
    expect($message->refresh()->attempts)->toBe(1)
        ->and($message->sent_at)->toBeNull();

    Date::setTestNow(now()->addSeconds(70));
    new SendMessageJob(true)->handle();

    $message->refresh();
    expect($message->sent_at)->not->toBeNull()
        ->and($message->attempts)->toBe(2);
    Mail::assertSent(TestMail::class);
});

it('counts only real attempts across error, deferral and a second error', function (): void {
    $message = createMessage([
        ...$this->base,
        'attempts' => 1,
        'error_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(30),
    ]);

    ($this->sentJustNow)();
    new SendMessageJob(true)->handle();
    expect($message->refresh()->attempts)->toBe(1);

    config()->set('messenger.sending.check_should_send', function (): bool {
        throw new RuntimeException('provider down');
    });
    Date::setTestNow(now()->addSeconds(70));
    new SendMessageJob(true)->handle();

    $message->refresh();
    expect($message->attempts)->toBe(2)
        ->and($message->error_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($message->sent_at)->toBeNull();

    // max_retry_attempts (2) reached: no further retry, even after the backoff.
    config()->set('messenger.sending.check_should_send', fn (): bool => true);
    Date::setTestNow(now()->addDay());
    new SendMessageJob(true)->handle();
    expect($message->refresh()->sent_at)->toBeNull();
    Mail::assertNothingSent();
});

it('still counts the attempt when shouldBeSentNow throws', function (): void {
    $type = createMessageType(['single_handler' => DeferralAttemptsThrowingHandler::class]);
    $message = createMessage([...$this->base, 'message_type_id' => $type->id]);

    new DeferralAttemptsThrowingHandler($message->load('messageType'))->send();

    $message->refresh();
    expect($message->attempts)->toBe(1)
        ->and($message->error_at)->not->toBeNull();
});
