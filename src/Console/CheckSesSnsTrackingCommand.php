<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

class CheckSesSnsTrackingCommand extends Command
{
    protected $signature = 'messenger:ses-sns:check-tracking';

    protected $aliases = ['messenger:ses-sns:check'];

    protected $description = 'Check SES/SNS tracking provisioning state via AWS API.';

    public function handle(SesSnsSetupService $service): int
    {
        try {
            $status = $service->check();

            foreach ($status['checks'] as $check) {
                $icon = $check['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $check['label'], $check['details']));
            }

            if ($status['ok']) {
                $this->info('SES/SNS setup is valid.');

                return self::SUCCESS;
            }

            $this->warn('SES/SNS setup is incomplete.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('SES/SNS check failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
