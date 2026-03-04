<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

class SetupSesSnsTrackingCommand extends Command
{
    protected $signature = 'messenger:ses-sns:setup-tracking';

    protected $aliases = ['messenger:ses-sns:setup'];

    protected $description = 'Provision SES v2 configuration set + SNS destination + subscription for messenger tracking.';

    public function handle(SesSnsSetupService $service): int
    {
        try {
            $result = $service->setup();

            foreach ($result['steps'] as $step) {
                $icon = $step['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $step['label'], $step['details']));
            }

            if ($result['ok']) {
                $this->info('SES/SNS setup completed and checks are green.');

                return self::SUCCESS;
            }

            $this->warn('SES/SNS setup executed, but checks are not fully green.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('SES/SNS setup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
