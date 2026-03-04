<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSendingSetupService;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

class SetupSesSnsAllCommand extends Command
{
    protected $signature = 'messenger:ses-sns:setup-all';

    protected $description = 'Provision SES sending + SES/SNS tracking (one-shot setup).';

    public function handle(SesSendingSetupService $sendingService, SesSnsSetupService $trackingService): int
    {
        try {
            $sendingResult = $sendingService->setup();
            $trackingResult = $trackingService->setup();

            $this->info('Sending setup');
            foreach ($sendingResult['steps'] as $step) {
                $icon = $step['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $step['label'], $step['details']));
            }

            $this->line('');
            $this->info('Tracking setup');
            foreach ($trackingResult['steps'] as $step) {
                $icon = $step['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $step['label'], $step['details']));
            }

            $ok = $sendingResult['ok'] && $trackingResult['ok'];
            if (! $ok) {
                $this->warn('Setup completed, but one or more checks are not fully green.');

                return self::FAILURE;
            }

            $this->info('SES/SNS one-shot setup completed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('SES/SNS one-shot setup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
