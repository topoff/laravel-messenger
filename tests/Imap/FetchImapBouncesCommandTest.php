<?php

declare(strict_types=1);

it('lists configured inboxes when invoked without an argument', function () {
    config()->set('messenger.imap.inboxes', [
        'topoffer_info' => ['host' => 'imap.example.com', 'username' => 'u', 'password' => 'p'],
    ]);

    $this->artisan('messenger:imap:fetch')
        ->expectsOutputToContain('Configured IMAP inboxes:')
        ->expectsOutputToContain('topoffer_info')
        ->assertSuccessful();
});

it('warns when no inboxes are configured', function () {
    config()->set('messenger.imap.inboxes', []);

    $this->artisan('messenger:imap:fetch')
        ->expectsOutputToContain('No IMAP inboxes configured.')
        ->assertSuccessful();
});

it('fails cleanly when invoked with an unknown inbox key', function () {
    config()->set('messenger.imap.inboxes', []);

    $this->artisan('messenger:imap:fetch', ['inbox' => 'missing'])
        ->expectsOutputToContain("Unknown IMAP inbox key 'missing'")
        ->assertFailed();
});

it('prints config in dry-run without connecting', function () {
    config()->set('messenger.imap.inboxes', [
        'topoffer_info' => [
            'host' => 'imap.example.com',
            'port' => 993,
            'username' => 'info@top-offerten.ch',
            'password' => 'secret',
            'folder' => 'INBOX',
        ],
    ]);

    $this->artisan('messenger:imap:fetch', ['inbox' => 'topoffer_info', '--dry-run' => true])
        ->expectsOutputToContain("Dry run for inbox 'topoffer_info'")
        ->expectsOutputToContain('host: imap.example.com')
        ->expectsOutputToContain('username: info@top-offerten.ch')
        ->expectsOutputToContain('No IMAP connection was attempted')
        ->assertSuccessful();
});
