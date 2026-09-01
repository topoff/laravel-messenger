<?php

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Topoff\Messenger\Listeners\AddListUnsubscribeHeadersListener;
use Topoff\Messenger\Models\MessageOptOut;
use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Services\ConsentService;
use Workbench\App\Models\TestReceiver;

function sendingEventFor($messageModel): MessageSending
{
    $email = new Message(new Email);
    $email->to('someone@example.com')->from('noreply@example.com')->html('<p>Hi</p>');

    return new MessageSending($email->getSymfonyMessage(), ['messageModel' => $messageModel]);
}

it('adds RFC 8058 headers to marketing mails only (v9)', function () {
    $receiver = createReceiver();

    $marketingType = createMessageType(['message_class' => MessageType::CLASS_MARKETING]);
    $marketing = createMessage(['message_type_id' => $marketingType->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiver->id]);

    $event = sendingEventFor($marketing);
    (new AddListUnsubscribeHeadersListener)->handle($event);

    expect($event->message->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toContain('emessenger/unsubscribe/'.$marketing->id)
        ->and($event->message->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');

    // Transactional: untouched.
    $transactional = createMessage(['receiver_type' => TestReceiver::class, 'receiver_id' => $receiver->id]);
    $event2 = sendingEventFor($transactional);
    (new AddListUnsubscribeHeadersListener)->handle($event2);

    expect($event2->message->getHeaders()->get('List-Unsubscribe'))->toBeNull();
});

it('unsubscribes via signed one-click POST and rejects unsigned calls', function () {
    $receiver = createReceiver();
    $marketingType = createMessageType(['message_class' => MessageType::CLASS_MARKETING]);
    $message = createMessage(['message_type_id' => $marketingType->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiver->id]);

    // Unsigned: rejected.
    $this->post('/emessenger/unsubscribe/'.$message->id)->assertForbidden();

    // Signed one-click POST: writes the marketing opt-out.
    $url = URL::signedRoute('messenger.unsubscribe', ['message' => $message->id]);
    $this->post($url)->assertOk();

    expect(MessageOptOut::where('receiver_id', (string) $receiver->id)->where('scope', 'marketing')->exists())->toBeTrue()
        ->and(app(ConsentService::class)->isOptedOut(TestReceiver::class, $receiver->id, $marketingType))->toBeTrue();
});
