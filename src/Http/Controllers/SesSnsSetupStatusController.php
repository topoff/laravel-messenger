<?php

declare(strict_types=1);

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Routing\Controller;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

class SesSnsSetupStatusController extends Controller
{
    public function __invoke(SesSnsSetupService $service)
    {
        return view('messenger::ses-sns-status', [
            'status' => $service->check(),
        ]);
    }
}
