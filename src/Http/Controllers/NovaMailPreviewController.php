<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Topoff\Messenger\Models\Message;

class NovaMailPreviewController
{
    public function show(Request $request, int $message)
    {
        $messageModelClass = config('messenger.models.message');
        $resolvedMessage = $messageModelClass::query()->with('messageType')->find($message);

        if (! $resolvedMessage instanceof Message) {
            throw new NotFoundHttpException('Message not found.');
        }

        $messageType = $resolvedMessage->messageType;

        $mailClass = (string) $messageType->notification_class;
        if ($mailClass === '' || ! class_exists($mailClass)) {
            throw new NotFoundHttpException('Mail class not found.');
        }

        $mailHandlerClass = (string) $messageType->single_handler;
        if ($mailHandlerClass === '' || ! class_exists($mailHandlerClass)) {
            throw new NotFoundHttpException('Single mail handler class not found.');
        }

        $mailHandler = new $mailHandlerClass($resolvedMessage);
        $mailParameters = $mailHandler->getMailParameters();

        return new $mailClass(...$mailParameters);
    }
}
