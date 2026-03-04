<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

class TeardownSesSnsTrackingCommand extends Command
{
    protected $signature = 'messenger:ses-sns:teardown {--force : Skip confirmation prompt}';

    protected $description = 'Remove SES/SNS tracking resources created by messenger setup.';

    public function handle(SesSnsSetupService $service): int
    {
        if (! $this->option('force')) {
            $confirmed = $this->confirm('This will remove SES event destination/configuration set and SNS topic/subscription. Continue?', false);
            if (! $confirmed) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        try {
            $result = $service->teardown();

            foreach ($result['steps'] as $step) {
                $icon = $step['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $step['label'], $step['details']));
            }

            $this->info('SES/SNS teardown completed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('SES/SNS teardown failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
