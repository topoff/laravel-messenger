<?php

declare(strict_types=1);

use Topoff\Messenger\Exceptions\ImapConfigurationException;
use Topoff\Messenger\Services\Imap\ImapClientFactory;

it('lists configured inbox keys', function () {
    config()->set('messenger.imap.inboxes', [
        'topoffer_info' => ['host' => 'imap.example.com', 'username' => 'u', 'password' => 'p'],
        'topoffer_support' => ['host' => 'imap2.example.com', 'username' => 'u', 'password' => 'p'],
    ]);

    expect(new ImapClientFactory()->configuredInboxKeys())
        ->toBe(['topoffer_info', 'topoffer_support']);
});

it('returns config for an existing inbox', function () {
    config()->set('messenger.imap.inboxes', [
        'topoffer_info' => [
            'host' => 'imap.example.com',
            'username' => 'info@top-offerten.ch',
            'password' => 'secret',
            'port' => 993,
        ],
    ]);

    $cfg = new ImapClientFactory()->configFor('topoffer_info');

    expect($cfg['host'])->toBe('imap.example.com')
        ->and($cfg['username'])->toBe('info@top-offerten.ch')
        ->and($cfg['port'])->toBe(993);
});

it('throws on unknown inbox key', function () {
    config()->set('messenger.imap.inboxes', []);

    new ImapClientFactory()->configFor('missing');
})->throws(ImapConfigurationException::class, "Unknown IMAP inbox key 'missing'");

it('throws when a required field is missing', function () {
    config()->set('messenger.imap.inboxes', [
        'partial' => ['host' => 'imap.example.com', 'username' => 'u'],
    ]);

    new ImapClientFactory()->configFor('partial');
})->throws(ImapConfigurationException::class, "missing required field 'password'");

it('resolves the imap_inbox configured on a ses configuration set', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'outreach' => [
            'configuration_set' => 'x',
            'event_destination' => 'y',
            'identity' => 'outreach',
            'imap_inbox' => 'topoffer_info',
        ],
        'transactional' => [
            'configuration_set' => 'x2',
            'event_destination' => 'y2',
            'identity' => 'default',
            // no imap_inbox -> not monitored
        ],
    ]);

    $factory = new ImapClientFactory;

    expect($factory->resolveInboxKeyForConfigurationSet('outreach'))->toBe('topoffer_info');
    expect($factory->resolveInboxKeyForConfigurationSet('transactional'))->toBeNull();
    expect($factory->resolveInboxKeyForConfigurationSet('unknown_set'))->toBeNull();
});
