<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Throwable;

class SesSnsDashboardCommandController extends Controller
{
    public function __invoke(Request $request, string $command): RedirectResponse
    {
        $commands = $this->commands();
        if (! array_key_exists($command, $commands)) {
            abort(403);
        }

        $definition = $commands[$command];

        try {
            $exitCode = Artisan::call($definition['command'], $definition['parameters']);
            $output = trim(Artisan::output());

            $result = [
                'command_key' => $command,
                'label' => $definition['label'],
                'command' => $definition['command'],
                'exit_code' => $exitCode,
                'ok' => $exitCode === 0,
                'output' => $output !== '' ? $output : '(no output)',
            ];
        } catch (Throwable $e) {
            $result = [
                'command_key' => $command,
                'label' => $definition['label'],
                'command' => $definition['command'],
                'exit_code' => 1,
                'ok' => false,
                'output' => $e->getMessage(),
            ];
        }

        return redirect()->to(URL::temporarySignedRoute('messenger.ses-sns.dashboard', now()->addMinutes(30)))
            ->with('messenger_ses_sns_command_result', $result);
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     command: string,
     *     parameters: array<string, mixed>
     * }>
     */
    protected function commands(): array
    {
        return [
            'setup-all' => [
                'label' => 'Setup SES/SNS All',
                'command' => 'messenger:ses-sns:setup-all',
                'parameters' => ['--no-interaction' => true],
            ],
            'setup-sending' => [
                'label' => 'Setup SES Sending',
                'command' => 'messenger:ses-sns:setup-sending',
                'parameters' => ['--no-interaction' => true],
            ],
            'check-sending' => [
                'label' => 'Check SES Sending',
                'command' => 'messenger:ses-sns:check-sending',
                'parameters' => ['--no-interaction' => true],
            ],
            'setup-tracking' => [
                'label' => 'Setup SES/SNS Tracking',
                'command' => 'messenger:ses-sns:setup-tracking',
                'parameters' => ['--no-interaction' => true],
            ],
            'check-tracking' => [
                'label' => 'Check SES/SNS Tracking',
                'command' => 'messenger:ses-sns:check-tracking',
                'parameters' => ['--no-interaction' => true],
            ],
            'test-delivery' => [
                'label' => 'Test Delivery Event',
                'command' => 'messenger:ses-sns:test-events',
                'parameters' => ['--scenario' => ['delivery'], '--wait' => 0, '--no-interaction' => true],
            ],
            'test-bounce' => [
                'label' => 'Test Bounce Event',
                'command' => 'messenger:ses-sns:test-events',
                'parameters' => ['--scenario' => ['bounce'], '--wait' => 0, '--no-interaction' => true],
            ],
            'test-complaint' => [
                'label' => 'Test Complaint Event',
                'command' => 'messenger:ses-sns:test-events',
                'parameters' => ['--scenario' => ['complaint'], '--wait' => 0, '--no-interaction' => true],
            ],
            'test-delivery-db' => [
                'label' => 'Test Delivery Event + DB Verify',
                'command' => 'messenger:ses-sns:test-events',
                'parameters' => ['--scenario' => ['delivery'], '--create-message-record' => true, '--wait' => 180, '--poll-interval' => 3, '--no-interaction' => true],
            ],
            'test-bounce-db' => [
                'label' => 'Test Bounce Event + DB Verify',
                'command' => 'messenger:ses-sns:test-events',
                'parameters' => ['--scenario' => ['bounce'], '--create-message-record' => true, '--wait' => 180, '--poll-interval' => 3, '--no-interaction' => true],
            ],
            'test-complaint-db' => [
                'label' => 'Test Complaint Event + DB Verify',
                'command' => 'messenger:ses-sns:test-events',
                'parameters' => ['--scenario' => ['complaint'], '--create-message-record' => true, '--wait' => 180, '--poll-interval' => 3, '--no-interaction' => true],
            ],
            'teardown' => [
                'label' => 'Teardown SES/SNS',
                'command' => 'messenger:ses-sns:teardown',
                'parameters' => ['--force' => true, '--no-interaction' => true],
            ],
        ];
    }
}
