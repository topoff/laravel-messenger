<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\ImapMessageProcessedEvent;
use Topoff\Messenger\Events\MessageComplaintEvent;
use Topoff\Messenger\Events\MessagePermanentBouncedEvent;
use Topoff\Messenger\Events\MessageReplyReceivedEvent;
use Topoff\Messenger\Events\MessageTransientBouncedEvent;
use Topoff\Messenger\Services\Imap\BounceClassification;
use Topoff\Messenger\Services\Imap\BounceClassifier;
use Topoff\Messenger\Services\Imap\ImapBounceProcessor;
use Topoff\Messenger\Services\Imap\InboundMessageParser;
use Topoff\Messenger\Services\Imap\InMemoryInboundMessageSource;
use Topoff\Messenger\Services\Imap\MessageMatcher;
use Topoff\Messenger\Services\Imap\ProcessedMessageTracker;

function readFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
}

function makeProcessor(): ImapBounceProcessor
{
    return new ImapBounceProcessor(
        parser: new InboundMessageParser,
        classifier: new BounceClassifier,
        matcher: new MessageMatcher,
        tracker: new ProcessedMessageTracker,
    );
}

it('records a hard bounce against the matched message and fires PermanentBouncedEvent', function () {
    Event::fake();

    $tracked = createMessage([
        'tracking_correlation_id' => '01234567-89ab-7cde-8123-456789abcdef',
        'tracking_recipient_contact' => 'broken@example.org',
    ]);

    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-1' => readFixture('postfix_hard_bounce.eml'),
    ]);

    $result = makeProcessor()->process($source);

    expect($result->hardBounces)->toBe(1);
    expect($result->softBounces)->toBe(0);

    $tracked->refresh();
    expect($tracked->bounced_at)->not->toBeNull();
    $meta = $tracked->tracking_meta;
    expect($meta['failures'][0]['source'])->toBe('imap');
    expect($meta['failures'][0]['status'])->toBe('5.1.1');
    expect($meta['failures'][0]['sub_type'])->toBe('NoEmail');
    expect($meta['success'])->toBeFalse();
    expect($meta['imap_message_bounce']['classification'])->toBe('hard_bounce');

    Event::assertDispatched(MessagePermanentBouncedEvent::class, fn ($e) => $e->message->id === $tracked->id);
    Event::assertDispatched(ImapMessageProcessedEvent::class);
});

it('records a soft bounce and fires TransientBouncedEvent with sub-type', function () {
    Event::fake();

    $tracked = createMessage([
        'tracking_correlation_id' => 'aaaaaaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee',
        'tracking_recipient_contact' => 'busy@example.net',
    ]);

    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-2' => readFixture('postfix_soft_bounce.eml'),
    ]);

    $result = makeProcessor()->process($source);

    expect($result->softBounces)->toBe(1);

    Event::assertDispatched(MessageTransientBouncedEvent::class, function ($e) use ($tracked) {
        return $e->message->id === $tracked->id
            && $e->bounceSubType === 'MailboxFull';
    });
});

it('records a complaint and fires ComplaintEvent', function () {
    Event::fake();

    $tracked = createMessage([
        'tracking_correlation_id' => '33334444-5555-7666-8777-888899990000',
        'tracking_recipient_contact' => 'subscriber@isp.example',
    ]);

    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-arf' => readFixture('arf_complaint.eml'),
    ]);

    $result = makeProcessor()->process($source);

    expect($result->complaints)->toBe(1);

    $tracked->refresh();
    expect($tracked->tracking_meta['complaint'])->toBeTrue();
    expect($tracked->tracking_meta['success'])->toBeFalse();
    expect($tracked->tracking_meta['complaint_type'])->toBe('abuse');

    Event::assertDispatched(MessageComplaintEvent::class, fn ($e) => $e->message->id === $tracked->id);
});

it('fires MessageReplyReceivedEvent for genuine replies', function () {
    Event::fake();

    $tracked = createMessage([
        'tracking_correlation_id' => '77778888-9999-7aaa-8bbb-cccccccccccc',
        'tracking_recipient_contact' => 'bob@customer.example',
    ]);

    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-reply' => readFixture('genuine_reply.eml'),
    ]);

    $result = makeProcessor()->process($source);

    expect($result->replies)->toBe(1);

    Event::assertDispatched(MessageReplyReceivedEvent::class, function ($e) use ($tracked) {
        return $e->message?->id === $tracked->id
            && $e->inboxKey === 'topoffer_info'
            && $e->fromAddress === 'bob@customer.example'
            && $e->subject === 'Re: Welcome to our service'
            && str_contains($e->textBody, 'billing address');
    });
});

it('fires MessageReplyReceivedEvent with null message when no match', function () {
    Event::fake();

    // No tracked message created — reply has nothing to match against.
    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-reply' => readFixture('genuine_reply.eml'),
    ]);

    $result = makeProcessor()->process($source);

    expect($result->replies)->toBe(1);

    Event::assertDispatched(MessageReplyReceivedEvent::class, fn ($e) => $e->message === null);
});

it('skips a duplicate message on a second processor pass', function () {
    createMessage([
        'tracking_correlation_id' => '01234567-89ab-7cde-8123-456789abcdef',
        'tracking_recipient_contact' => 'broken@example.org',
    ]);

    $sourceA = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-1' => readFixture('postfix_hard_bounce.eml'),
    ]);
    $first = makeProcessor()->process($sourceA);

    $sourceB = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-1' => readFixture('postfix_hard_bounce.eml'),
    ]);
    $second = makeProcessor()->process($sourceB);

    expect($first->hardBounces)->toBe(1)
        ->and($first->skippedDuplicate)->toBe(0);
    expect($second->hardBounces)->toBe(0)
        ->and($second->skippedDuplicate)->toBe(1);
});

it('does not flip success to false when a prior delivery confirmation exists', function () {
    Event::fake();

    $tracked = createMessage([
        'tracking_correlation_id' => '01234567-89ab-7cde-8123-456789abcdef',
        'tracking_recipient_contact' => 'broken@example.org',
        'tracking_meta' => ['success' => true, 'sns_message_delivery' => ['ok' => true]],
    ]);

    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-1' => readFixture('postfix_hard_bounce.eml'),
    ]);
    makeProcessor()->process($source);

    $tracked->refresh();
    expect($tracked->tracking_meta['success'])->toBeTrue();
});

it('calls markProcessed with the right classification', function () {
    createMessage([
        'tracking_correlation_id' => '01234567-89ab-7cde-8123-456789abcdef',
        'tracking_recipient_contact' => 'broken@example.org',
    ]);

    $source = new InMemoryInboundMessageSource('topoffer_info', [
        'uid-1' => readFixture('postfix_hard_bounce.eml'),
        'uid-2' => readFixture('genuine_reply.eml'),
    ]);
    makeProcessor()->process($source);

    expect($source->marked)->toBe([
        ['uid' => 'uid-1', 'classification' => BounceClassification::HardBounce],
        ['uid' => 'uid-2', 'classification' => BounceClassification::Reply],
    ]);
});
