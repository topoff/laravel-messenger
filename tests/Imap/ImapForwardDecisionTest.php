<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Topoff\Messenger\Events\MessageReplyReceivedEvent;
use Topoff\Messenger\Mail\ForwardedInboundMail;
use Topoff\Messenger\Services\Imap\BounceClassifier;
use Topoff\Messenger\Services\Imap\ImapBounceProcessor;
use Topoff\Messenger\Services\Imap\InboundMailForwarder;
use Topoff\Messenger\Services\Imap\InboundMessageParser;
use Topoff\Messenger\Services\Imap\InMemoryInboundMessageSource;
use Topoff\Messenger\Services\Imap\MessageMatcher;
use Topoff\Messenger\Services\Imap\ProcessedMessageTracker;

function processFixture(string $fixture): void
{
    $processor = new ImapBounceProcessor(
        parser: new InboundMessageParser,
        classifier: new BounceClassifier,
        matcher: new MessageMatcher,
        tracker: new ProcessedMessageTracker,
        forwarder: new InboundMailForwarder,
    );

    $processor->process(new InMemoryInboundMessageSource('noreply-topofferten', [
        'uid-1' => readImapFixture($fixture),
    ]));
}

beforeEach(function () {
    config()->set('messenger.imap.forward.unhandled_to', 'info@top-offerten.ch');
    config()->set('messenger.imap.forward.from', 'no-reply@top-offerten.ch');
    config()->set('messenger.imap.forward.bcc');
});

it('forwards a reply that no listener marked as handled', function () {
    Mail::fake();

    createMessage([
        'tracking_correlation_id' => '77778888-9999-7aaa-8bbb-cccccccccccc',
        'tracking_recipient_contact' => 'bob@customer.example',
    ]);

    processFixture('genuine_reply.eml');

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->hasTo('info@top-offerten.ch')
        && $mail->reason === 'unhandled_reply');
});

it('does not forward a reply a listener marked as handled', function () {
    Mail::fake();

    Event::listen(MessageReplyReceivedEvent::class, function (MessageReplyReceivedEvent $event): void {
        $event->markHandled();
    });

    createMessage([
        'tracking_correlation_id' => '77778888-9999-7aaa-8bbb-cccccccccccc',
        'tracking_recipient_contact' => 'bob@customer.example',
    ]);

    processFixture('genuine_reply.eml');

    Mail::assertNothingSent();
});

it('always forwards mail classified as unknown', function () {
    Mail::fake();

    processFixture('unsolicited_with_attachment.eml');

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->reason === 'unknown_classification');
});

it('never forwards bounces, complaints or auto-replies', function (string $fixture) {
    Mail::fake();

    processFixture($fixture);

    Mail::assertNothingSent();
})->with([
    'hard bounce' => 'postfix_hard_bounce.eml',
    'soft bounce' => 'postfix_soft_bounce.eml',
    'complaint' => 'arf_complaint.eml',
    'auto reply' => 'auto_reply_vacation.eml',
]);

it('never forwards inbound mail carrying our own forwarding marker', function () {
    Mail::fake();

    processFixture('own_forward_returned.eml');

    Mail::assertNothingSent();
});

it('never forwards mail the mail host flagged as spam', function () {
    Mail::fake();

    processFixture('spam_flagged_reply.eml');

    Mail::assertNothingSent();
});

it('keeps the log-only behavior when no forward target is configured', function () {
    config()->set('messenger.imap.forward.unhandled_to');
    Mail::fake();

    processFixture('unsolicited_with_attachment.eml');
    processFixture('genuine_reply.eml');

    Mail::assertNothingSent();
});

it('still forwards a reply when the listener throws — a failing listener must not lose the mail', function () {
    Mail::fake();

    Event::listen(MessageReplyReceivedEvent::class, function (): void {
        throw new RuntimeException('listener exploded');
    });

    createMessage([
        'tracking_correlation_id' => '77778888-9999-7aaa-8bbb-cccccccccccc',
        'tracking_recipient_contact' => 'bob@customer.example',
    ]);

    processFixture('genuine_reply.eml');

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->hasTo('info@top-offerten.ch')
        && $mail->reason === 'unhandled_reply');
});
