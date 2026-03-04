<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Topoff\Messenger\Mail\CustomMessageMail;
use Topoff\Messenger\Models\Message;

class NovaCustomMessagePreviewController
{
    public function show(Request $request): Response
    {
        $key = (string) $request->query('key');
        $payload = Cache::get($key);

        abort_if(! is_array($payload), 404);

        $message = new Message([
            'params' => ['subject' => (string) ($payload['subject'] ?? ''), 'text' => (string) ($payload['markdown'] ?? '')],
            'receiver_type' => (string) ($payload['model_type'] ?? ''),
        ]);

        $html = (new CustomMessageMail($message))->render();

        return response($html);
    }
}
