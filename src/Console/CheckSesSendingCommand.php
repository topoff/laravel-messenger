<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSendingSetupService;

class CheckSesSendingCommand extends Command
{
    protected $signature = 'messenger:ses-sns:check-sending';

    protected $description = 'Check SES sending identity and DNS-related status.';

    public function handle(SesSendingSetupService $service): int
    {
        try {
            $status = $service->check();

            foreach ($status['checks'] as $check) {
                $icon = $check['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('[%s] %s - %s', $icon, $check['label'], $check['details']));
            }

            if ($status['dns_records'] !== []) {
                $this->line('');
                $this->info('Expected DNS records:');
                foreach ($status['dns_records'] as $record) {
                    $this->line(sprintf('- %s %s %s', $record['type'], $record['name'], implode(' | ', $record['values'])));
                }
            }

            if ($status['ok']) {
                $this->info('SES sending setup is valid.');

                return self::SUCCESS;
            }

            $this->warn('SES sending setup is incomplete.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('SES sending check failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
