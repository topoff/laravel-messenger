<?php

use Topoff\Messenger\MailHandler\MainBulkMailHandler;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Models\MessageType;
use Workbench\App\Mail\TestMail;
use Workbench\App\MailHandler\TestMailHandler;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;
use Workbench\App\Models\TestSender;

function createMessageType(array $attributes = []): MessageType
{
    return MessageType::create(array_merge([
        'notification_class' => TestMail::class,
        'single_handler' => TestMailHandler::class,
        'bulk_handler' => MainBulkMailHandler::class,
        'direct' => true,
        'dev_bcc' => true,
        'error_stop_send_minutes' => 60 * 24 * 3,
        'required_sender' => false,
        'required_messagable' => false,
        'required_company_id' => false,
        'required_scheduled' => false,
        'required_text' => false,
        'required_params' => false,
        'bulk_message_line' => 'Test bulk line',
    ], $attributes));
}

function createReceiver(array $attributes = []): TestReceiver
{
    return TestReceiver::create(array_merge([
        'email' => 'receiver@example.com',
        'locale' => 'en',
    ], $attributes));
}

function createSender(array $attributes = []): TestSender
{
    return TestSender::create(array_merge([
        'name' => 'Test Sender',
    ], $attributes));
}

function createMessagable(array $attributes = []): TestMessagable
{
    return TestMessagable::create(array_merge([
        'title' => 'Test Messagable',
    ], $attributes));
}

function createMessage(array $attributes = []): Message
{
    $messageType = $attributes['message_type_id'] ?? createMessageType()->id;
    $receiver = $attributes['receiver_type'] ?? TestReceiver::class;
    $receiverId = $attributes['receiver_id'] ?? createReceiver()->id;
    $messagable = $attributes['messagable_type'] ?? TestMessagable::class;
    $messagableId = $attributes['messagable_id'] ?? createMessagable()->id;

    return Message::create(array_merge([
        'receiver_type' => $receiver,
        'receiver_id' => $receiverId,
        'message_type_id' => $messageType,
        'messagable_type' => $messagable,
        'messagable_id' => $messagableId,
    ], $attributes));
}
