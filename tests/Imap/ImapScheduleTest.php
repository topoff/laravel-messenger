<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('does not register any IMAP scheduler entries when imap is disabled', function () {
    config()->set('messenger.imap.enabled', false);
    config()->set('messenger.imap.inboxes', [
        'topoffer_info' => ['host' => 'h', 'username' => 'u', 'password' => 'p'],
    ]);

    // Force a fresh schedule resolution.
    $this->app->forgetInstance(Schedule::class);
    $schedule = $this->app->make(Schedule::class);

    $imapEvents = collect($schedule->events())
        ->filter(fn ($e) => str_starts_with((string) $e->description, 'messenger.imap.fetch.'));

    expect($imapEvents)->toHaveCount(0);
});

it('registers one scheduler entry per inbox when imap is enabled', function () {
    config()->set('messenger.imap.enabled', true);
    config()->set('messenger.imap.inboxes', [
        'topoffer_info' => ['host' => 'h', 'username' => 'u', 'password' => 'p'],
        'topoffer_support' => ['host' => 'h2', 'username' => 'u', 'password' => 'p'],
    ]);
    config()->set('messenger.imap.schedule.cron', '*/15 * * * *');

    $this->app->forgetInstance(Schedule::class);
    $schedule = $this->app->make(Schedule::class);

    $names = collect($schedule->events())
        ->map(fn ($e) => (string) $e->description)
        ->filter(fn (string $d) => str_starts_with($d, 'messenger.imap.fetch.'))
        ->values()
        ->all();

    expect($names)->toBe([
        'messenger.imap.fetch.topoffer_info',
        'messenger.imap.fetch.topoffer_support',
    ]);

    $expressions = collect($schedule->events())
        ->filter(fn ($e) => str_starts_with((string) $e->description, 'messenger.imap.fetch.'))
        ->map(fn ($e) => $e->expression)
        ->unique()
        ->values()
        ->all();

    expect($expressions)->toBe(['*/15 * * * *']);
});
