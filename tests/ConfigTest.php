<?php

it('has default model classes configured', function () {
    expect(config('messenger.models.message'))->toBe(\Topoff\Messenger\Models\Message::class)
        ->and(config('messenger.models.message_type'))->toBe(\Topoff\Messenger\Models\MessageType::class);
});

it('allows overriding model classes', function () {
    config()->set('messenger.models.message', 'App\\Models\\CustomMessage');

    expect(config('messenger.models.message'))->toBe('App\\Models\\CustomMessage');
});

it('has default cache settings', function () {
    expect(config('messenger.cache.tag'))->toBe('messageType')
        ->and(config('messenger.cache.ttl'))->toBe(60 * 60 * 24 * 30);
});

it('has default bulk mail class configured', function () {
    expect(config('messenger.mail.default_bulk_mail_class'))->toBe(\Topoff\Messenger\Mail\BulkMail::class);
});

it('has default bulk mail view configured', function () {
    expect(config('messenger.mail.bulk_mail_view'))->toBe('messenger::bulkMail');
});

it('has null defaults for callable configs', function () {
    expect(config('messenger.mail.bulk_mail_subject'))->toBeNull()
        ->and(config('messenger.mail.bulk_mail_url'))->toBeNull()
        ->and(config('messenger.sending.check_should_send'))->toBeNull()
        ->and(config('messenger.sending.prevent_create_message'))->toBeNull()
        ->and(config('messenger.bcc.check_should_add_bcc'))->toBeNull();
});

it('has nova tracking defaults configured', function () {
    expect(config('messenger.tracking.nova.enabled'))->toBeTrue()
        ->and(config('messenger.tracking.nova.register_resource'))->toBeFalse()
        ->and(config('messenger.tracking.nova.resource'))->toBe(\Topoff\Messenger\Nova\Resources\Message::class)
        ->and(config('messenger.tracking.nova.preview_route.prefix'))->toBe('emessenger/nova');
});

it('has ses sns setup defaults configured', function () {
    expect(config('messenger.ses_sns.configuration_sets.default.identity'))->toBe('default')
        ->and(config('messenger.ses_sns.sending.identities.default'))->toBe([
            'identity_domain' => null,
            'mail_from_domain' => null,
            'mail_from_address' => null,
        ])
        ->and(config('messenger.ses_sns.sending.identities'))->toBe([
            'default' => [
                'identity_domain' => null,
                'mail_from_domain' => null,
                'mail_from_address' => null,
            ],
        ])
        ->and(config('messenger.ses_sns.topic_name'))->toEndWith('messenger-ses-events')
        ->and(config('messenger.ses_sns.event_types'))->toBe(['SEND', 'REJECT', 'BOUNCE', 'COMPLAINT', 'DELIVERY']);
});
