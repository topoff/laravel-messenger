<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Topoff\Messenger\Jobs\RecordVonageDlrJob;

class VonageDlrController extends Controller
{
    public function callback(Request $request): string
    {
        if (! config('messenger.tracking.vonage_dlr.enabled', false)) {
            return 'vonage dlr disabled';
        }

        $data = $request->all();
        if ($data === []) {
            return 'empty payload';
        }

        RecordVonageDlrJob::dispatch($data)
            ->onQueue(config('messenger.tracking.tracker_queue'));

        return 'ok';
    }
}
