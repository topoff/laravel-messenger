<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Topoff\Messenger\Events\SesSnsWebhookReceivedEvent;

it('records delivery notifications via sns callback', function () {
    Event::fake([SesSnsWebhookReceivedEvent::class]);

    $message = createMessage([
        'tracking_message_id' => 'delivery-mid-1',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'delivery-mid-1'],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['receiver@example.com'],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and(data_get($message->tracking_meta, 'smtpResponse'))->toBe('250 Ok')
        ->and($message->delivered_at)->not->toBeNull();

    Event::assertDispatched(SesSnsWebhookReceivedEvent::class, fn (SesSnsWebhookReceivedEvent $event): bool => $event->notificationType === 'Delivery'
        && $event->processedSynchronously === false
        && data_get($event->sesMessage, 'mail.messageId') === 'delivery-mid-1');
});

it('records bounce notifications via sns callback', function () {
    $message = createMessage([
        'tracking_message_id' => 'bounce-mid-1',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => 'bounce-mid-1'],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'timestamp' => '2026-01-01T00:05:00Z',
                'bouncedRecipients' => [
                    ['emailAddress' => 'receiver@example.com', 'diagnosticCode' => '550 No such user'],
                ],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeFalse()
        ->and(data_get($message->tracking_meta, 'failures.0.emailAddress'))->toBe('receiver@example.com')
        ->and($message->bounced_at)->not->toBeNull()
        ->and($message->delivered_at)->toBeNull();
});

it('does not flip success to false when a bounce arrives after a successful delivery', function () {
    $message = createMessage([
        'tracking_message_id' => 'accept-then-bounce-mid',
        'tracking_meta' => [],
    ]);

    $this->postJson(route('messenger.tracking.sns'), [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'accept-then-bounce-mid'],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['receiver@example.com'],
            ],
        ]),
    ])->assertOk();

    $this->postJson(route('messenger.tracking.sns'), [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => 'accept-then-bounce-mid'],
            'bounce' => [
                'bounceType' => 'Transient',
                'bounceSubType' => 'General',
                'timestamp' => '2026-01-01T00:00:05Z',
                'bouncedRecipients' => [
                    ['emailAddress' => 'receiver@example.com'],
                ],
            ],
        ]),
    ])->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and(data_get($message->tracking_meta, 'failures.0.emailAddress'))->toBe('receiver@example.com')
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->bounced_at)->not->toBeNull();
});

it('records complaint notifications via sns callback', function () {
    $message = createMessage([
        'tracking_message_id' => 'complaint-mid-1',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Complaint',
            'mail' => ['messageId' => 'complaint-mid-1'],
            'complaint' => [
                'timestamp' => '2026-01-01T01:00:00Z',
                'complaintFeedbackType' => 'abuse',
                'complainedRecipients' => [
                    ['emailAddress' => 'receiver@example.com'],
                ],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'complaint'))->toBeTrue()
        ->and(data_get($message->tracking_meta, 'complaint_type'))->toBe('abuse');
});

it('processes messenger test notifications synchronously', function () {
    Queue::fake();

    $message = createMessage([
        'tracking_message_id' => 'delivery-mid-sync',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => 'delivery-mid-sync',
                'tags' => [
                    ['name' => 'messenger_test', 'value' => 'true'],
                ],
            ],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['receiver@example.com'],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and(data_get($message->tracking_meta, 'smtpResponse'))->toBe('250 Ok');

    Queue::assertNothingPushed();
});

it('processes eventType payload format from ses with tag map', function () {
    Queue::fake();

    $message = createMessage([
        'tracking_message_id' => 'delivery-mid-event-type',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'eventType' => 'Delivery',
            'mail' => [
                'messageId' => 'delivery-mid-event-type',
                'commonHeaders' => [
                    'subject' => '[messenger][delivery] 2026-02-27 13:33:49',
                ],
                'tags' => [
                    'messenger_test' => ['true'],
                    'scenario' => ['delivery'],
                ],
            ],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['success@simulator.amazonses.com'],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and(data_get($message->tracking_meta, 'smtpResponse'))->toBe('250 Ok');

    Queue::assertNothingPushed();
});

it('extracts ses_tags from delivery notification into tracking_meta', function () {
    $message = createMessage([
        'tracking_message_id' => 'delivery-mid-tags',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => 'delivery-mid-tags',
                'tags' => [
                    'tenant_id' => ['my-tenant'],
                    'stream' => ['marketing'],
                    'mail_type' => ['TestMail'],
                ],
            ],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['receiver@example.com'],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'ses_tags'))->toBe([
        'tenant_id' => 'my-tenant',
        'stream' => 'marketing',
        'mail_type' => 'TestMail',
    ]);
});

it('skips delivery event when recipient does not match tracking_recipient_contact', function () {
    $message = createMessage([
        'tracking_message_id' => 'delivery-mid-bcc',
        'tracking_recipient_contact' => 'alice@example.com',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'delivery-mid-bcc'],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['bcc@example.com'],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect($message->tracking_meta)->toBeEmpty();
});

it('skips bounce event when recipient does not match tracking_recipient_contact', function () {
    $message = createMessage([
        'tracking_message_id' => 'bounce-mid-bcc',
        'tracking_recipient_contact' => 'alice@example.com',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => 'bounce-mid-bcc'],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'bouncedRecipients' => [
                    ['emailAddress' => 'bcc@example.com', 'diagnosticCode' => '550 No such user'],
                ],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect($message->tracking_meta)->toBeEmpty();
});

it('skips complaint event when recipient does not match tracking_recipient_contact', function () {
    $message = createMessage([
        'tracking_message_id' => 'complaint-mid-bcc',
        'tracking_recipient_contact' => 'alice@example.com',
        'tracking_meta' => [],
    ]);

    $payload = [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Complaint',
            'mail' => ['messageId' => 'complaint-mid-bcc'],
            'complaint' => [
                'timestamp' => '2026-01-01T01:00:00Z',
                'complaintFeedbackType' => 'abuse',
                'complainedRecipients' => [
                    ['emailAddress' => 'bcc@example.com'],
                ],
            ],
        ]),
    ];

    $this->postJson(route('messenger.tracking.sns'), $payload)->assertOk();

    $message->refresh();

    expect($message->tracking_meta)->toBeEmpty();
});
