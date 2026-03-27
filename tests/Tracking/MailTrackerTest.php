<?php

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as IlluminateSentMessage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Topoff\Messenger\Tracking\MailTracker;

it('injects tracking pixel and links and persists tracking metadata on messageSending', function () {
    config()->set('messenger.tracking.inject_pixel', true);
    config()->set('messenger.tracking.track_links', true);
    config()->set('messenger.tracking.log_content', true);
    config()->set('messenger.tracking.log_content_strategy', 'database');

    $messageModel = createMessage();

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('receiver@example.com', 'Receiver Name'))
        ->subject('Tracking Subject')
        ->html('<html><body><a href="https://example.com/path?x=1&amp;y=2">Test</a></body></html>');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);

    app(MailTracker::class)->messageSending($event);

    $messageModel->refresh();

    expect($messageModel->tracking_hash)->not->toBeNull()
        ->and($messageModel->tracking_sender_contact)->toBe('sender@example.com')
        ->and($messageModel->tracking_recipient_contact)->toBe('receiver@example.com')
        ->and($messageModel->tracking_subject)->toBe('Tracking Subject')
        ->and($messageModel->tracking_opens)->toBe(0)
        ->and($messageModel->tracking_clicks)->toBe(0)
        ->and($messageModel->tracking_content)->toContain('https://example.com/path?x=1&amp;y=2');

    $body = $email->getBody()->getBody() ?? '';
    $expectedTrackedUrl = URL::signedRoute('messenger.tracking.click', [
        'l' => 'https://example.com/path?x=1&y=2',
        'h' => $messageModel->tracking_hash,
    ]);

    expect($body)
        ->toContain('/email/t/'.$messageModel->tracking_hash)
        ->toContain('/email/n?')
        ->toContain('h='.$messageModel->tracking_hash)
        ->toContain($expectedTrackedUrl)
        ->not->toContain('href="https://example.com/path?x=1&amp;y=2"');
});

it('writes tracking message id when message is sent', function () {
    $messageModel = createMessage(['tracking_hash' => 'testhash123']);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('receiver@example.com', 'Receiver Name'))
        ->subject('Tracking Subject')
        ->text('Plain text');

    $email->getHeaders()->addTextHeader('X-Mailer-Hash', 'testhash123');
    $email->getHeaders()->addTextHeader('X-SES-Message-ID', 'ses-message-id-123');

    $symfonySent = new SymfonySentMessage(
        $email,
        new Envelope(new Address('sender@example.com'), [new Address('receiver@example.com')])
    );

    $event = new MessageSent(new IlluminateSentMessage($symfonySent), []);

    app(MailTracker::class)->messageSent($event);

    $messageModel->refresh();

    expect($messageModel->tracking_message_id)->toBe('ses-message-id-123');
});

it('writes tracking message id from transport when ses header is missing', function () {
    $messageModel = createMessage(['tracking_hash' => 'smtp-hash-123']);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('receiver@example.com', 'Receiver Name'))
        ->subject('SMTP Tracking Subject')
        ->text('Plain text');

    $email->getHeaders()->addTextHeader('X-Mailer-Hash', 'smtp-hash-123');

    $symfonySent = new SymfonySentMessage(
        $email,
        new Envelope(new Address('sender@example.com'), [new Address('receiver@example.com')])
    );

    $event = new MessageSent(new IlluminateSentMessage($symfonySent), []);

    app(MailTracker::class)->messageSent($event);

    $messageModel->refresh();

    expect($messageModel->tracking_message_id)->not->toBeNull();
});

it('applies the same tracking hash to all grouped messages for bulk sends', function () {
    config()->set('messenger.tracking.inject_pixel', true);
    config()->set('messenger.tracking.track_links', true);

    $m1 = createMessage();
    $m2 = createMessage([
        'receiver_type' => $m1->receiver_type,
        'receiver_id' => $m1->receiver_id,
    ]);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('receiver@example.com', 'Receiver Name'))
        ->subject('Bulk Tracking Subject')
        ->html('<html><body><a href="https://example.com/bulk">Bulk</a></body></html>');

    $event = new MessageSending($email, ['messages' => collect([$m1, $m2])]);
    app(MailTracker::class)->messageSending($event);

    $m1->refresh();
    $m2->refresh();

    expect($m1->tracking_hash)->not->toBeNull()
        ->and($m2->tracking_hash)->toBe($m1->tracking_hash)
        ->and($m1->tracking_subject)->toBe('Bulk Tracking Subject')
        ->and($m2->tracking_subject)->toBe('Bulk Tracking Subject');
});

it('injects X-SES-CONFIGURATION-SET header when messageType has ses_configuration_set', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'transactional' => [
            'configuration_set' => 'my-tenant-prod-transactional',
            'event_destination' => 'my-tenant-prod-transactional-sns',
            'identity' => 'default',
        ],
    ]);

    $messageType = createMessageType(['ses_configuration_set' => 'transactional']);
    $messageModel = createMessage(['message_type_id' => $messageType->id]);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender'))
        ->to(new Address('receiver@example.com', 'Receiver'))
        ->subject('Config Set Test')
        ->text('Plain text');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);
    app(MailTracker::class)->messageSending($event);

    expect($email->getHeaders()->get('X-SES-CONFIGURATION-SET')?->getBodyAsString())
        ->toBe('my-tenant-prod-transactional');
});

it('overrides From address based on identity mapped to config set', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'outreach' => [
            'configuration_set' => 'my-tenant-prod-outreach',
            'event_destination' => 'my-tenant-prod-outreach-sns',
            'identity' => 'outreach',
        ],
    ]);
    config()->set('messenger.ses_sns.sending.identities', [
        'outreach' => [
            'identity_domain' => 'connect.example.com',
            'mail_from_domain' => 'bounce.connect.example.com',
            'mail_from_address' => 'outreach@connect.example.com',
        ],
    ]);

    $messageType = createMessageType(['ses_configuration_set' => 'outreach']);
    $messageModel = createMessage(['message_type_id' => $messageType->id]);

    $email = (new Email)
        ->from(new Address('original@example.com', 'Original Name'))
        ->to(new Address('receiver@example.com', 'Receiver'))
        ->subject('From Override Test')
        ->text('Plain text');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);
    app(MailTracker::class)->messageSending($event);

    $from = collect($email->getFrom())->first();
    expect($from->getAddress())->toBe('outreach@connect.example.com')
        ->and($from->getName())->toBe('Original Name');

    $messageModel->refresh();
    expect($messageModel->tracking_sender_contact)->toBe('outreach@connect.example.com');
});

it('injects X-SES-MESSAGE-TAGS header with tenant, stream, mail_type', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'marketing' => [
            'configuration_set' => 'my-tenant-prod-marketing',
            'event_destination' => 'my-tenant-prod-marketing-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.tenant.name', 'my-tenant-prod-tenant');

    $messageType = createMessageType(['ses_configuration_set' => 'marketing']);
    $messageModel = createMessage(['message_type_id' => $messageType->id]);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender'))
        ->to(new Address('receiver@example.com', 'Receiver'))
        ->subject('Tags Test')
        ->text('Plain text');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);
    app(MailTracker::class)->messageSending($event);

    $tagsHeader = $email->getHeaders()->get('X-SES-MESSAGE-TAGS')?->getBodyAsString();
    expect($tagsHeader)->toContain('tenant_id=my-tenant-prod-tenant')
        ->toContain('stream=marketing')
        ->toContain('mail_type=TestMail');
});

it('does not override From when identity has no mail_from_address', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'my-tenant-prod-tracking',
            'event_destination' => 'my-tenant-prod-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.sending.identities', [
        'default' => [
            'identity_domain' => 'example.com',
            'mail_from_domain' => 'bounce.example.com',
        ],
    ]);

    $messageType = createMessageType(['ses_configuration_set' => 'default']);
    $messageModel = createMessage(['message_type_id' => $messageType->id]);

    $email = (new Email)
        ->from(new Address('original@example.com', 'Original Name'))
        ->to(new Address('receiver@example.com', 'Receiver'))
        ->subject('No Override Test')
        ->text('Plain text');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);
    app(MailTracker::class)->messageSending($event);

    $from = collect($email->getFrom())->first();
    expect($from->getAddress())->toBe('original@example.com');
});

it('injects Reply-To from identity config when configured', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'outreach' => [
            'configuration_set' => 'my-tenant-prod-outreach',
            'event_destination' => 'my-tenant-prod-outreach-sns',
            'identity' => 'outreach',
        ],
    ]);
    config()->set('messenger.ses_sns.sending.identities', [
        'outreach' => [
            'identity_domain' => 'business.example.com',
            'mail_from_domain' => 'bounce.business.example.com',
            'mail_from_address' => 'welcome@business.example.com',
            'reply_to_address' => 'info@example.com',
        ],
    ]);

    $messageType = createMessageType(['ses_configuration_set' => 'outreach']);
    $messageModel = createMessage(['message_type_id' => $messageType->id]);

    $email = (new Email)
        ->from(new Address('original@example.com', 'Original Name'))
        ->to(new Address('receiver@example.com', 'Receiver'))
        ->subject('Reply-To Test')
        ->text('Plain text');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);
    app(MailTracker::class)->messageSending($event);

    $replyTo = collect($email->getReplyTo())->first();
    expect($replyTo)->not->toBeNull()
        ->and($replyTo->getAddress())->toBe('info@example.com');
});

it('does not override existing Reply-To header', function () {
    config()->set('messenger.ses_sns.configuration_sets', [
        'outreach' => [
            'configuration_set' => 'my-tenant-prod-outreach',
            'event_destination' => 'my-tenant-prod-outreach-sns',
            'identity' => 'outreach',
        ],
    ]);
    config()->set('messenger.ses_sns.sending.identities', [
        'outreach' => [
            'identity_domain' => 'business.example.com',
            'mail_from_domain' => 'bounce.business.example.com',
            'mail_from_address' => 'welcome@business.example.com',
            'reply_to_address' => 'info@example.com',
        ],
    ]);

    $messageType = createMessageType(['ses_configuration_set' => 'outreach']);
    $messageModel = createMessage(['message_type_id' => $messageType->id]);

    $email = (new Email)
        ->from(new Address('original@example.com', 'Original Name'))
        ->to(new Address('receiver@example.com', 'Receiver'))
        ->replyTo(new Address('custom-reply@example.com'))
        ->subject('Existing Reply-To Test')
        ->text('Plain text');

    $event = new MessageSending($email, ['messageModel' => $messageModel]);
    app(MailTracker::class)->messageSending($event);

    $replyTo = collect($email->getReplyTo())->first();
    expect($replyTo->getAddress())->toBe('custom-reply@example.com');
});
