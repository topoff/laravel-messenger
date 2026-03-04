<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as IlluminateSentMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Topoff\Messenger\Listeners\LogEmailToMessageLogListener;
use Topoff\Messenger\Models\MessageLog;

beforeEach(function () {
    $this->listener = new LogEmailToMessageLogListener;
});

it('writes a row with channel mail and populates email-specific fields', function () {
    $email = new Email;
    $email->from(new Address('sender@example.com'));
    $email->to(new Address('recipient@example.com'));
    $email->subject('Test Subject');
    $email->cc(new Address('cc@example.com'));
    $email->bcc(new Address('bcc@example.com'));
    $email->text('Body');

    $symfonySent = new SymfonySentMessage(
        $email,
        new Envelope(new Address('sender@example.com'), [new Address('recipient@example.com')])
    );
    $event = new MessageSent(new IlluminateSentMessage($symfonySent));

    $this->listener->handle($event);

    $log = MessageLog::query()->first();
    expect($log)->not->toBeNull()
        ->and($log->channel)->toBe('mail')
        ->and($log->to)->toContain('recipient@example.com')
        ->and($log->subject)->toContain('Test Subject')
        ->and($log->cc)->toContain('cc@example.com')
        ->and($log->bcc)->toContain('bcc@example.com')
        ->and($log->has_attachment)->toBeFalse()
        ->and($log->notifyable_id)->toBeNull()
        ->and($log->notification_id)->toBeNull()
        ->and($log->type)->toBeNull();
});

it('returns early when To header is null', function () {
    // Create an email with Bcc only (no To header) — valid per RFC but triggers early return
    $email = new Email;
    $email->from(new Address('sender@example.com'));
    $email->bcc(new Address('bcc-only@example.com'));
    $email->text('Body');

    $symfonySent = new SymfonySentMessage(
        $email,
        new Envelope(new Address('sender@example.com'), [new Address('bcc-only@example.com')])
    );
    $event = new MessageSent(new IlluminateSentMessage($symfonySent));

    $this->listener->handle($event);

    expect(MessageLog::query()->count())->toBe(0);
});
