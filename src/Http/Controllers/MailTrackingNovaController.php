<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Topoff\Messenger\Models\Message;

class MailTrackingNovaController extends Controller
{
    public function preview(int $id): Response
    {
        $messageClass = config('messenger.models.message');
        /** @var Message $message */
        $message = $messageClass::query()->findOrFail($id);

        $html = $message->tracking_content;
        if (! $html && $message->tracking_content_path) {
            $disk = config('messenger.tracking.tracker_filesystem');
            try {
                $html = $disk
                    ? (Storage::disk($disk)->exists($message->tracking_content_path) ? Storage::disk($disk)->get($message->tracking_content_path) : null)
                    : (Storage::exists($message->tracking_content_path) ? Storage::get($message->tracking_content_path) : null);
            } catch (\Throwable) {
                $html = null;
            }
        }

        if (! $html) {
            $html = '<html><body><p>No tracked content available for preview.</p></body></html>';
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
