<?php

namespace Topoff\MailManager\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Topoff\MailManager\Events\MessageTrackingValidActionEvent;
use Topoff\MailManager\Jobs\RecordLinkClickJob;
use Topoff\MailManager\Jobs\RecordOpenJob;

class MailTrackingController extends Controller
{
    public function open(Request $request, string $hash): \Illuminate\Http\Response
    {
        $pixel = sprintf(
            '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c',
            71, 73, 70, 56, 57, 97, 1, 0, 1, 0, 128, 255, 0, 192, 192, 192, 0, 0, 0, 33, 249, 4, 1, 0, 0, 0, 0, 44, 0, 0, 0, 0, 1, 0, 1, 0, 0, 2, 2, 68, 1, 0, 59
        );

        $response = Response::make($pixel, 200);
        $response->header('Content-type', 'image/gif');
        $response->header('Content-Length', (string) mb_strlen($pixel));
        $response->header('Cache-Control', 'private, no-cache, no-cache=Set-Cookie, proxy-revalidate');
        $response->header('Expires', 'Wed, 11 Jan 2000 12:59:00 GMT');
        $response->header('Last-Modified', 'Wed, 11 Jan 2006 12:59:00 GMT');
        $response->header('Pragma', 'no-cache');

        $messageClass = config('mail-manager.models.message');
        $messages = $messageClass::query()->where('tracking_hash', $hash)->get();

        if ($messages->isNotEmpty()) {
            $event = new MessageTrackingValidActionEvent($messages->first());
            Event::dispatch($event);

            if (! $event->skip) {
                $ip = $request->ip();
                $messages->each(function ($message) use ($ip): void {
                    RecordOpenJob::dispatch($message->getKey(), $ip)->onQueue(config('mail-manager.tracking.tracker_queue'));

                    if (! $message->tracking_opened_at) {
                        $message->tracking_opened_at = now();
                        $message->save();
                    }
                });
            }
        }

        return $response;
    }

    public function click(Request $request): RedirectResponse
    {
        $url = (string) ($request->query('l') ?: config('mail-manager.tracking.redirect_missing_links_to', '/'));
        $hash = (string) $request->query('h');

        $messageClass = config('mail-manager.models.message');
        $messages = $messageClass::query()->where('tracking_hash', $hash)->get();

        if ($messages->isNotEmpty()) {
            $event = new MessageTrackingValidActionEvent($messages->first());
            Event::dispatch($event);

            if (! $event->skip) {
                $ip = $request->ip();
                $messages->each(function ($message) use ($url, $ip): void {
                    RecordLinkClickJob::dispatch($message->getKey(), $url, $ip)->onQueue(config('mail-manager.tracking.tracker_queue'));

                    if (config('mail-manager.tracking.inject_pixel') && ! $message->tracking_opened_at) {
                        $message->tracking_opened_at = now();
                    }

                    if (! $message->tracking_clicked_at) {
                        $message->tracking_clicked_at = now();
                    }

                    $message->save();
                });
            }
        }

        return redirect($url);
    }
}
