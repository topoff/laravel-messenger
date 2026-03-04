<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSendingSetupService;

class SetupSesSendingCommand extends Command
{
    protected $signature = 'messenger:ses-sns:setup-sending';

    protected $description = 'Provision SES sending identity and DNS requirements.';

    public function handle(SesSendingSetupService $service): int
    {
        try {
            $result = $service->setup();

            foreach ($result['steps'] as $step) {
                $icon = $step['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $step['label'], $step['details']));
            }

            if ($result['dns_records'] !== []) {
                $this->line('');
                $this->info('DNS records to verify/apply:');
                foreach ($result['dns_records'] as $record) {
                    $this->line(sprintf('- %s %s %s', $record['type'], $record['name'], implode(' | ', $record['values'])));
                }
            }

            $this->info('SES sending setup completed.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('SES sending setup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
