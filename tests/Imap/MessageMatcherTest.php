<?php

declare(strict_types=1);

use Topoff\Messenger\Services\Imap\BounceClassification;
use Topoff\Messenger\Services\Imap\BounceReport;
use Topoff\Messenger\Services\Imap\MessageMatcher;

it('matches by tracking_correlation_id when present', function () {
    $message = createMessage([
        'tracking_correlation_id' => '01234567-89ab-7cde-8123-456789abcdef',
        'tracking_recipient_contact' => 'broken@example.org',
    ]);

    $report = new BounceReport(
        classification: BounceClassification::HardBounce,
        statusCode: '5.1.1',
        recipients: ['broken@example.org'],
        originalCorrelationId: '01234567-89ab-7cde-8123-456789abcdef',
    );

    $matches = new MessageMatcher()->match($report);

    expect($matches->pluck('id')->all())->toBe([$message->id]);
});

it('falls back to ses message id when correlation id misses', function () {
    $message = createMessage([
        'tracking_message_id' => 'ses-id-7777',
        'tracking_recipient_contact' => 'user@example.com',
    ]);

    $report = new BounceReport(
        classification: BounceClassification::HardBounce,
        statusCode: '5.1.1',
        recipients: ['user@example.com'],
        originalCorrelationId: null,
        originalSesMessageId: 'ses-id-7777',
    );

    $matches = new MessageMatcher()->match($report);

    expect($matches->pluck('id')->all())->toBe([$message->id]);
});

it('falls back to recipient + recent window when no ids are known', function () {
    $recent = createMessage([
        'tracking_recipient_contact' => 'busy@example.net',
    ]);

    $report = new BounceReport(
        classification: BounceClassification::SoftBounce,
        statusCode: '4.2.2',
        recipients: ['busy@example.net'],
    );

    $matches = new MessageMatcher()->match($report);

    expect($matches->pluck('id')->all())->toContain($recent->id);
});

it('does not match an old message via recipient fallback', function () {
    createMessage([
        'tracking_recipient_contact' => 'old@example.com',
        'created_at' => now()->subYear(),
    ]);

    $report = new BounceReport(
        classification: BounceClassification::SoftBounce,
        statusCode: '4.2.2',
        recipients: ['old@example.com'],
    );

    $matches = new MessageMatcher()->match($report);

    expect($matches)->toHaveCount(0);
});

it('returns an empty collection when nothing matches', function () {
    $report = new BounceReport(
        classification: BounceClassification::HardBounce,
        statusCode: '5.1.1',
        recipients: ['unknown@nowhere.example'],
        originalCorrelationId: 'ffffffff-ffff-7fff-8fff-ffffffffffff',
    );

    $matches = new MessageMatcher()->match($report);

    expect($matches)->toHaveCount(0);
});
