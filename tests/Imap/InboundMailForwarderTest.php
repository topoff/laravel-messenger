<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Topoff\Messenger\Mail\ForwardedInboundMail;
use Topoff\Messenger\Services\Imap\InboundMailForwarder;
use Topoff\Messenger\Services\Imap\InboundMessageParser;

function forwardFixture(string $fixture, string $reason = 'unknown_classification'): bool
{
    $raw = readImapFixture($fixture);

    return (new InboundMailForwarder)->forward(
        (new InboundMessageParser)->parse($raw),
        $raw,
        'noreply-topofferten',
        $reason,
    );
}

function lastSentEmail(): Email
{
    /** @var Email $email */
    $email = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return $email;
}

beforeEach(function () {
    config()->set('mail.default', 'array');
    config()->set('messenger.imap.forward.unhandled_to', 'info@top-offerten.ch');
    config()->set('messenger.imap.forward.from', 'no-reply@top-offerten.ch');
    config()->set('messenger.imap.forward.bcc');
});

it('forwards to the configured address with reply-to on the original sender', function () {
    Mail::fake();

    expect(forwardFixture('unsolicited_with_attachment.eml'))->toBeTrue();

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->hasTo('info@top-offerten.ch')
        && $mail->hasFrom('no-reply@top-offerten.ch')
        && $mail->hasReplyTo('beatrice@kundin.example')
        && $mail->forwardSubject() === 'Fwd: Grüezi – Rückfrage zur Offerte');
});

it('adds the configured bcc to the forwarding mail', function () {
    config()->set('messenger.imap.forward.bcc', 'andi@top-offerten.ch');
    Mail::fake();

    forwardFixture('unsolicited_with_attachment.eml');

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->hasBcc('andi@top-offerten.ch'));
});

it('sends no bcc when none is configured', function () {
    Mail::fake();

    forwardFixture('unsolicited_with_attachment.eml');

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->bcc === []);
});

it('carries the marker header, the original body and both attachments', function () {
    expect(forwardFixture('unsolicited_with_attachment.eml'))->toBeTrue();

    $email = lastSentEmail();

    expect($email->getHeaders()->get(ForwardedInboundMail::MARKER_HEADER)?->getBodyAsString())->toBe('1')
        ->and($email->getSubject())->toBe('Fwd: Grüezi – Rückfrage zur Offerte')
        ->and($email->getTo()[0]->getAddress())->toBe('info@top-offerten.ch')
        ->and($email->getFrom()[0]->getAddress())->toBe('no-reply@top-offerten.ch')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('beatrice@kundin.example');

    $text = (string) $email->getTextBody();
    expect($text)->toContain('Forwarded message')
        ->toContain('Beatrice Muster <beatrice@kundin.example>')
        ->toContain('Ich haette eine Rueckfrage zur Offerte.');

    $attachments = collect($email->getAttachments());
    $names = $attachments->map(fn ($part): ?string => $part->getFilename())->all();

    expect($names)->toBe(['rechnung.pdf', 'original-message.eml']);

    $pdf = $attachments->firstWhere(fn ($part): bool => $part->getFilename() === 'rechnung.pdf');
    expect($pdf->getBody())->toContain('PDF-DUMMY-PAYLOAD')
        ->and($pdf->getContentType())->toBe('application/pdf');

    $eml = $attachments->firstWhere(fn ($part): bool => $part->getFilename() === 'original-message.eml');
    expect($eml->getBody())->toBe(readImapFixture('unsolicited_with_attachment.eml'))
        ->and($eml->getContentType())->toBe('message/rfc822');
});

it('does not send anything when no forward target is configured', function () {
    config()->set('messenger.imap.forward.unhandled_to');
    Mail::fake();

    expect(forwardFixture('unsolicited_with_attachment.eml'))->toBeFalse();

    Mail::assertNothingSent();
});

it('never forwards mail flagged as spam by the mail host', function () {
    Mail::fake();

    expect(forwardFixture('spam_flagged_reply.eml', 'unhandled_reply'))->toBeFalse();

    Mail::assertNothingSent();
});

it('forwards a spam-flagged mail once the header is no longer configured as a spam marker', function () {
    config()->set('messenger.imap.forward.spam_headers', []);
    Mail::fake();

    expect(forwardFixture('spam_flagged_reply.eml', 'unhandled_reply'))->toBeTrue();

    Mail::assertSent(ForwardedInboundMail::class);
});

it('never forwards a mail that already carries our own marker header', function () {
    Mail::fake();

    expect(forwardFixture('own_forward_returned.eml', 'unhandled_reply'))->toBeFalse();

    Mail::assertNothingSent();
});

it('reports a failed send instead of throwing', function () {
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

    expect(forwardFixture('unsolicited_with_attachment.eml'))->toBeFalse();
});

it('ignores an attacker-controlled reply-to header and answers to the From address instead', function () {
    Mail::fake();

    expect(forwardFixture('spoofed_reply_to.eml'))->toBeTrue();

    Mail::assertSent(ForwardedInboundMail::class, fn (ForwardedInboundMail $mail): bool => $mail->hasReplyTo('beatrice@kundin.example')
        && ! $mail->hasReplyTo('attacker@evil.example'));
});

it('never forwards mail coming from one of our own addresses, even without the marker header', function () {
    Mail::fake();

    expect(forwardFixture('from_own_address.eml'))->toBeFalse();

    Mail::assertNothingSent();
});
