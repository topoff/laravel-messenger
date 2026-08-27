<?php

declare(strict_types=1);

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Topoff\Messenger\Services\FallbackEngine;

/**
 * Scheduled runner for the status-driven fallback engine (v9, E79).
 */
class RunFallbackEngineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FallbackEngine $engine): void
    {
        $engine->run();
    }
}
