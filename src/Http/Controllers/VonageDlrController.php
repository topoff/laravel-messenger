<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Jobs\RecordVonageDlrJob;

class VonageDlrController extends Controller
{
    private static bool $warnedAboutMissingToken = false;

    public function callback(Request $request): Response|string
    {
        if (! config('messenger.tracking.vonage_dlr.enabled', false)) {
            return 'vonage dlr disabled';
        }

        // Shared secret in the DLR URL (?token=…). Without a configured token the
        // endpoint stays open as before (backwards compatibility for hosts that
        // already enabled it) — set one: anyone can otherwise forge receipts.
        $token = (string) config('messenger.tracking.vonage_dlr.token');
        if ($token !== '') {
            if (! hash_equals($token, (string) $request->query('token', $request->input('token', '')))) {
                return response('invalid token', 403);
            }
        } elseif (! self::$warnedAboutMissingToken) {
            self::$warnedAboutMissingToken = true;
            Log::warning('VonageDlrController: messenger.tracking.vonage_dlr.token is not set; DLR endpoint accepts unauthenticated requests.');
        }

        // Never hand the secret to the queue (payloads end up in failed_jobs and logs).
        $data = $request->except('token');
        if ($data === []) {
            return 'empty payload';
        }

        RecordVonageDlrJob::dispatch($data)
            ->onQueue(config('messenger.tracking.tracker_queue'));

        return 'ok';
    }
}
