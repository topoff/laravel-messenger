<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Topoff\Messenger\Services\ConsentService;

/**
 * RFC 8058 one-click unsubscribe endpoint (v9, E79). POST is the
 * one-click path used by mailbox providers; GET serves as a plain
 * fallback landing for clients without POST support. The route is
 * signature-protected — the URL only ever leaves the system inside the
 * List-Unsubscribe header of a marketing mail.
 */
class UnsubscribeController
{
    public function __invoke(Request $request, ConsentService $consent): Response
    {
        $messageClass = config('messenger.models.message');
        $message = $messageClass::findOrFail($request->route('message'));

        $consent->optOut($message->receiver_type, $message->receiver_id);

        return new Response('You have been unsubscribed. / Sie wurden abgemeldet.', 200, ['Content-Type' => 'text/plain']);
    }
}
