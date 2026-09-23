<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Topoff\Messenger\Mail\NoReplyAutoResponseMail;
use Topoff\Messenger\Services\Imap\InboundMailForwarder;
use Topoff\Messenger\Services\Imap\InboundMessageParser;
use Topoff\Messenger\Services\Imap\UnhandledMailAutoResponder;

function respondRaw(string $raw, string $reason = 'unknown_classification'): bool
{
    return new UnhandledMailAutoResponder(new InboundMailForwarder)->respond(
        (new InboundMessageParser)->parse($raw),
        'noreply-topofferten',
        $reason,
    );
}

function respondToFixture(string $fixture, string $reason = 'unknown_classification'): bool
{
    return respondRaw(readImapFixture($fixture), $reason);
}

function lastAutoResponse(): Email
{
    /** @var Email $email */
    $email = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return $email;
}

beforeEach(function () {
    config()->set('mail.default', 'array');
    config()->set('mail.from.address', 'mailer@top-offerten.ch');
    config()->set('messenger.imap.auto_reply.enabled', true);
    config()->set('messenger.imap.auto_reply.contact_address', 'info@top-offerten.ch');
    config()->set('messenger.imap.auto_reply.from', 'no-reply@top-offerten.ch');
    config()->set('messenger.imap.auto_reply.bcc');
    config()->set('messenger.imap.forward.unhandled_to', 'info@top-offerten.ch');
    config()->set('messenger.imap.forward.from', 'no-reply@top-offerten.ch');
});

it('answers the sender with the contact address and a Re: subject', function () {
    Mail::fake();

    expect(respondToFixture('unsolicited_with_attachment.eml'))->toBeTrue();

    Mail::assertSent(NoReplyAutoResponseMail::class, fn (NoReplyAutoResponseMail $mail): bool => $mail->hasTo('beatrice@kundin.example')
        && $mail->hasFrom('no-reply@top-offerten.ch')
        && $mail->hasReplyTo('info@top-offerten.ch')
        && $mail->contactAddress === 'info@top-offerten.ch'
        && $mail->noReplyAddress === 'no-reply@top-offerten.ch'
        && $mail->responseSubject() === 'Re: Grüezi – Rückfrage zur Offerte');
});

it('renders the notice with both addresses and quotes nothing from the original', function () {
    expect(respondToFixture('unsolicited_with_attachment.eml'))->toBeTrue();

    $body = (string) lastAutoResponse()->getTextBody();

    expect($body)
        ->toContain('no-reply@top-offerten.ch')
        ->toContain('info@top-offerten.ch')
        ->toContain('werden nicht gelesen')
        ->not->toContain('Rueckfrage zur Offerte');
});

it('marks the response as automated mail with the loop-protection marker', function () {
    expect(respondToFixture('unsolicited_with_attachment.eml'))->toBeTrue();

    $headers = lastAutoResponse()->getHeaders();

    expect($headers->has(NoReplyAutoResponseMail::MARKER_HEADER))->toBeTrue()
        ->and($headers->getHeaderBody('Auto-Submitted'))->toBe('auto-replied')
        ->and($headers->has('X-Auto-Response-Suppress'))->toBeTrue()
        ->and($headers->has('Precedence'))->toBeTrue();
});

it('threads the response onto the original message id', function () {
    $raw = <<<'EML'
From: Beatrice Muster <beatrice@kundin.example>
To: no-reply@top-offerten.ch
Subject: Frage zur Rechnung
Message-ID: <original-123@kundin.example>
Date: Mon, 01 Sep 2026 08:30:00 +0200
MIME-Version: 1.0
Content-Type: text/plain; charset=utf-8

Wo finde ich meine Rechnung?
EML;

    expect(respondRaw($raw))->toBeTrue();

    expect((string) lastAutoResponse()->getHeaders()->getHeaderBody('References'))
        ->toContain('original-123@kundin.example');
});

it('answers at most once per sender within the throttle window', function () {
    Mail::fake();

    expect(respondToFixture('unsolicited_with_attachment.eml'))->toBeTrue()
        ->and(respondToFixture('unsolicited_with_attachment.eml'))->toBeFalse();

    Mail::assertSentCount(1);
});

it('answers again when the throttle is disabled', function () {
    config()->set('messenger.imap.auto_reply.throttle_hours', 0);
    Mail::fake();

    expect(respondToFixture('unsolicited_with_attachment.eml'))->toBeTrue()
        ->and(respondToFixture('unsolicited_with_attachment.eml'))->toBeTrue();
});

it('adds the configured bcc to the response', function () {
    config()->set('messenger.imap.auto_reply.bcc', 'andi@top-offerten.ch');
    Mail::fake();

    respondToFixture('unsolicited_with_attachment.eml');

    Mail::assertSent(NoReplyAutoResponseMail::class, fn (NoReplyAutoResponseMail $mail): bool => $mail->hasBcc('andi@top-offerten.ch'));
});

it('never answers spam-flagged mail', function () {
    Mail::fake();

    expect(respondToFixture('spam_flagged_reply.eml'))->toBeFalse();

    Mail::assertNothingSent();
});

it('never answers mail from one of our own addresses', function () {
    Mail::fake();

    expect(respondToFixture('from_own_address.eml'))->toBeFalse();

    Mail::assertNothingSent();
});

it('never answers our own returned forwards or responses', function () {
    Mail::fake();

    expect(respondToFixture('own_forward_returned.eml'))->toBeFalse();

    $raw = str_replace('X-Topoff-Forwarded: 1', NoReplyAutoResponseMail::MARKER_HEADER.': 1', readImapFixture('own_forward_returned.eml'));
    expect(respondRaw($raw))->toBeFalse();

    Mail::assertNothingSent();
});

it('never answers automated senders', function (string $headerLine, string $from) {
    Mail::fake();

    $raw = <<<EML
From: {$from}
To: no-reply@top-offerten.ch
Subject: Newsletter September
{$headerLine}
Date: Mon, 01 Sep 2026 08:30:00 +0200
MIME-Version: 1.0
Content-Type: text/plain; charset=utf-8

Inhalt.
EML;

    expect(respondRaw($raw))->toBeFalse();

    Mail::assertNothingSent();
})->with([
    'precedence bulk' => ['Precedence: bulk', 'news@partner.example'],
    'list mail' => ['List-Unsubscribe: <mailto:unsub@partner.example>', 'news@partner.example'],
    'response suppression' => ['X-Auto-Response-Suppress: All', 'system@partner.example'],
    'mailer daemon' => ['X-Anything: 1', 'MAILER-DAEMON@partner.example'],
    'no-reply sender' => ['X-Anything: 1', 'no-reply@partner.example'],
    'bounce sender' => ['X-Anything: 1', 'bounces+42@partner.example'],
]);

it('does nothing without a valid sender address', function () {
    Mail::fake();

    $raw = <<<'EML'
From: Undisclosed recipients
To: no-reply@top-offerten.ch
Subject: Kaputter Absender
Date: Mon, 01 Sep 2026 08:30:00 +0200
MIME-Version: 1.0
Content-Type: text/plain; charset=utf-8

Inhalt.
EML;

    expect(respondRaw($raw))->toBeFalse();

    Mail::assertNothingSent();
});

it('is disabled without the flag or without a contact address', function () {
    $responder = new UnhandledMailAutoResponder(new InboundMailForwarder);

    config()->set('messenger.imap.auto_reply.enabled', false);
    expect($responder->isEnabled())->toBeFalse();

    config()->set('messenger.imap.auto_reply.enabled', true);
    config()->set('messenger.imap.auto_reply.contact_address');
    expect($responder->isEnabled())->toBeFalse();

    Mail::fake();
    expect(respondToFixture('unsolicited_with_attachment.eml'))->toBeFalse();
    Mail::assertNothingSent();
});
