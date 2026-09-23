<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Topoff\Messenger\Events\MessageReplyReceivedEvent;
use Topoff\Messenger\Mail\ForwardedInboundMail;
use Topoff\Messenger\Mail\NoReplyAutoResponseMail;
use Topoff\Messenger\Services\Imap\BounceClassifier;
use Topoff\Messenger\Services\Imap\ImapBounceProcessor;
use Topoff\Messenger\Services\Imap\InboundMailForwarder;
use Topoff\Messenger\Services\Imap\InboundMessageParser;
use Topoff\Messenger\Services\Imap\InMemoryInboundMessageSource;
use Topoff\Messenger\Services\Imap\MessageMatcher;
use Topoff\Messenger\Services\Imap\ProcessedMessageTracker;
use Topoff\Messenger\Services\Imap\UnhandledMailAutoResponder;

function processFixture(string $fixture): void
{
    $processor = new ImapBounceProcessor(
        parser: new InboundMessageParser,
        classifier: new BounceClassifier,
        matcher: new MessageMatcher,
        tracker: new ProcessedMessageTracker,
        forwarder: $forwarder = new InboundMailForwarder,
        responder: new UnhandledMailAutoResponder($forwarder),
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

it('answers instead of forwarding when the auto-responder is enabled', function () {
    config()->set('messenger.imap.auto_reply.enabled', true);
    config()->set('messenger.imap.auto_reply.contact_address', 'info@top-offerten.ch');
    Mail::fake();

    processFixture('unsolicited_with_attachment.eml');

    Mail::assertSent(NoReplyAutoResponseMail::class, fn (NoReplyAutoResponseMail $mail): bool => $mail->hasTo('beatrice@kundin.example')
        && $mail->contactAddress === 'info@top-offerten.ch');
    Mail::assertNotSent(ForwardedInboundMail::class);
});

it('answers an unhandled reply when the auto-responder is enabled', function () {
    config()->set('messenger.imap.auto_reply.enabled', true);
    config()->set('messenger.imap.auto_reply.contact_address', 'info@top-offerten.ch');
    Mail::fake();

    createMessage([
        'tracking_correlation_id' => '77778888-9999-7aaa-8bbb-cccccccccccc',
        'tracking_recipient_contact' => 'bob@customer.example',
    ]);

    processFixture('genuine_reply.eml');

    Mail::assertSent(NoReplyAutoResponseMail::class, fn (NoReplyAutoResponseMail $mail): bool => $mail->hasTo('bob@customer.example'));
    Mail::assertNotSent(ForwardedInboundMail::class);
});

it('neither answers nor forwards guard-blocked mail when the auto-responder is enabled', function () {
    config()->set('messenger.imap.auto_reply.enabled', true);
    config()->set('messenger.imap.auto_reply.contact_address', 'info@top-offerten.ch');
    Mail::fake();

    processFixture('spam_flagged_reply.eml');

    Mail::assertNothingSent();
});
