<?php

declare(strict_types=1);

use Topoff\Messenger\Services\Imap\BounceClassification;
use Topoff\Messenger\Services\Imap\BounceClassifier;
use Topoff\Messenger\Services\Imap\InboundMessageParser;

function classifyFixture(string $name)
{
    $message = new InboundMessageParser()->parse((string) file_get_contents(__DIR__.'/fixtures/'.$name));

    return new BounceClassifier()->classify($message);
}

it('classifies postfix DSN with Status 5.1.1 as hard bounce', function () {
    $report = classifyFixture('postfix_hard_bounce.eml');

    expect($report->classification)->toBe(BounceClassification::HardBounce);
    expect($report->statusCode)->toBe('5.1.1');
    expect($report->recipients)->toBe(['broken@example.org']);
    expect($report->originalCorrelationId)->toBe('01234567-89ab-7cde-8123-456789abcdef');
    expect($report->originalSubject)->toBe('Your weekly digest');
    expect($report->bouncedAt?->format('Y-m-d H:i:s'))->toBe('2026-06-03 09:59:55');
    expect($report->subType)->toBe('NoEmail');
    expect($report->isPermanent())->toBeTrue();
});

it('classifies postfix DSN with Status 4.2.2 as soft bounce + MailboxFull', function () {
    $report = classifyFixture('postfix_soft_bounce.eml');

    expect($report->classification)->toBe(BounceClassification::SoftBounce);
    expect($report->statusCode)->toBe('4.2.2');
    expect($report->recipients)->toBe(['busy@example.net']);
    expect($report->originalCorrelationId)->toBe('aaaaaaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee');
    expect($report->subType)->toBe('MailboxFull');
    expect($report->isPermanent())->toBeFalse();
});

it('classifies exchange DSN with Status 5.0.0 + diagnostic 550 as hard bounce', function () {
    $report = classifyFixture('exchange_hard_bounce.eml');

    expect($report->classification)->toBe(BounceClassification::HardBounce);
    expect($report->statusCode)->toBe('5.0.0');
    expect($report->recipients)->toBe(['user.unknown@example.com']);
    expect($report->originalCorrelationId)->toBe('22223333-4444-7555-8666-777788889999');
    expect($report->originalMessageId)->toBe('22223333-4444-7555-8666-777788889999@bounce.mailer.top-offerten.ch');
    expect($report->diagnosticCode)->toContain('550');
});

it('classifies ARF feedback-report as complaint', function () {
    $report = classifyFixture('arf_complaint.eml');

    expect($report->classification)->toBe(BounceClassification::Complaint);
    expect($report->recipients)->toBe(['subscriber@isp.example']);
    expect($report->originalCorrelationId)->toBe('33334444-5555-7666-8777-888899990000');
    expect($report->subType)->toBe('abuse');
});

it('classifies Auto-Submitted header as auto reply', function () {
    $report = classifyFixture('auto_reply_vacation.eml');

    expect($report->classification)->toBe(BounceClassification::AutoReply);
    expect($report->originalMessageId)->toBe('55556666-7777-7888-8999-aaaabbbbcccc@bounce.mailer.top-offerten.ch');
    expect($report->originalCorrelationId)->toBe('55556666-7777-7888-8999-aaaabbbbcccc');
});

it('classifies a real human reply as Reply', function () {
    $report = classifyFixture('genuine_reply.eml');

    expect($report->classification)->toBe(BounceClassification::Reply);
    expect($report->originalCorrelationId)->toBe('77778888-9999-7aaa-8bbb-cccccccccccc');
});

it('extracts SES message id from In-Reply-To when SES rewrote our Message-ID header', function () {
    // Apple/Outlook reply clients quote whatever Message-ID the sending MTA put on the wire.
    // SES replaces our `<uuid@bounce.mailer...>` stamp with `<ses-msg-id@region.amazonses.com>`,
    // so In-Reply-To carries the SES ID — not our correlation UUID. The classifier must
    // pull that SES ID out so MessageMatcher can look it up via tracking_message_id.
    $report = classifyFixture('ses_rewritten_reply.eml');

    expect($report->classification)->toBe(BounceClassification::Reply);
    expect($report->originalMessageId)->toBe(
        '0102030405060708-aabbccdd-1122-3344-5566-778899aabbcc-000000@eu-central-1.amazonses.com'
    );
    expect($report->originalSesMessageId)->toBe(
        '0102030405060708-aabbccdd-1122-3344-5566-778899aabbcc-000000'
    );
});
