<?php

use Topoff\MailManager\Tracking\MessageResender;

it('creates and sends a resent tracked message', function () {
    config()->set('mail-manager.tracking.inject_pixel', true);
    config()->set('mail-manager.tracking.track_links', true);
    config()->set('mail-manager.sending.check_should_send', fn () => true);

    $original = createMessage([
        'tracking_hash' => 'originalhash001',
        'tracking_message_id' => 'original-mid',
        'tracking_sender_contact' => 'sender@example.com',
        'tracking_recipient_contact' => 'receiver@example.com',
        'tracking_subject' => 'Original Subject',
        'tracking_opens' => 2,
        'tracking_clicks' => 1,
        'sent_at' => now(),
    ]);

    /** @var MessageResender $resender */
    $resender = app(MessageResender::class);
    $resent = $resender->resend($original);
    $resent->refresh();

    expect($resent->id)->not->toBe($original->id)
        ->and($resent->sent_at)->not->toBeNull()
        ->and($resent->tracking_hash)->not->toBeNull()
        ->and($resent->tracking_subject)->toBe('Test Mail')
        ->and(data_get($resent->tracking_meta, 'resent_from_message_id'))->toBe($original->id);
});
