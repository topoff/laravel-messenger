<?php

namespace Topoff\Messenger\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Throwable;
use Topoff\Messenger\Services\SesSns\SesSendingSetupService;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

class SesSnsDashboardController extends Controller
{
    public function __invoke()
    {
        $tracking = $this->resolveTrackingStatus();
        $sending = $this->resolveSendingStatus();

        return view('messenger::ses-sns-dashboard', [
            'tracking' => $tracking,
            'sending' => $sending,
            'routes' => [
                'tracking_open' => Route::has('messenger.tracking.open') ? route('messenger.tracking.open', ['hash' => 'tracking_hash']) : null,
                'tracking_click' => Route::has('messenger.tracking.click') ? route('messenger.tracking.click', ['l' => '{signed_target_url}', 'h' => '{tracking_hash}']) : null,
                'sns_callback' => Route::has('messenger.tracking.sns') ? route('messenger.tracking.sns') : null,
            ],
            'commands' => [
                'php artisan messenger:ses-sns:setup-all',
                'php artisan messenger:ses-sns:setup-sending',
                'php artisan messenger:ses-sns:check-sending',
                'php artisan messenger:ses-sns:setup-tracking',
                'php artisan messenger:ses-sns:check-tracking',
                'php artisan messenger:ses-sns:test-events --scenario=delivery --wait=0',
                'php artisan messenger:ses-sns:test-events --scenario=bounce --wait=0',
                'php artisan messenger:ses-sns:test-events --scenario=complaint --wait=0',
                'php artisan messenger:ses-sns:test-events --scenario=delivery --create-message-record --wait=180 --poll-interval=3',
                'php artisan messenger:ses-sns:test-events --scenario=bounce --create-message-record --wait=180 --poll-interval=3',
                'php artisan messenger:ses-sns:test-events --scenario=complaint --create-message-record --wait=180 --poll-interval=3',
                'php artisan messenger:ses-sns:teardown --force',
            ],
            'command_buttons' => [
                [
                    'label' => 'Setup SES/SNS All',
                    'description' => 'One-shot setup for SES sending, tracking, and tenant associations.',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'setup-all']),
                ],
                [
                    'label' => 'Setup SES Sending',
                    'description' => 'Create/check SES identity and expected DNS records.',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'setup-sending']),
                ],
                [
                    'label' => 'Check SES Sending',
                    'description' => 'Validate SES sending identity and verification state.',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'check-sending']),
                ],
                [
                    'label' => 'Setup SES/SNS Tracking',
                    'description' => 'Provision SES configuration set + SNS destination/subscription.',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'setup-tracking']),
                ],
                [
                    'label' => 'Check SES/SNS Tracking',
                    'description' => 'Validate current SES/SNS tracking setup status.',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'check-tracking']),
                ],
                [
                    'label' => 'Test Delivery Event',
                    'description' => 'Send SES simulator delivery event (success@simulator.amazonses.com).',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'test-delivery']),
                ],
                [
                    'label' => 'Test Bounce Event',
                    'description' => 'Send SES simulator bounce event (bounce@simulator.amazonses.com).',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'test-bounce']),
                ],
                [
                    'label' => 'Test Complaint Event',
                    'description' => 'Send SES simulator complaint event (complaint@simulator.amazonses.com).',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'test-complaint']),
                ],
                [
                    'label' => 'Test Delivery Event + DB Verify',
                    'description' => 'Send delivery simulator event and verify tracking_meta updates in messages table (test events are processed synchronously in the SNS webhook).',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'test-delivery-db']),
                ],
                [
                    'label' => 'Test Bounce Event + DB Verify',
                    'description' => 'Send bounce simulator event and verify tracking_meta updates in messages table (test events are processed synchronously in the SNS webhook).',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'test-bounce-db']),
                ],
                [
                    'label' => 'Test Complaint Event + DB Verify',
                    'description' => 'Send complaint simulator event and verify tracking_meta updates in messages table (test events are processed synchronously in the SNS webhook).',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'test-complaint-db']),
                ],
                [
                    'label' => 'Teardown SES/SNS',
                    'description' => 'Remove SES/SNS tracking resources for cleanup.',
                    'url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.command', now()->addMinutes(30), ['command' => 'teardown']),
                ],
            ],
            'custom_mail_action_url' => URL::temporarySignedRoute('messenger.ses-sns.dashboard.custom-mail', now()->addMinutes(30)),
            'bcc_address' => config('mail.bcc.address'),
            'app_config' => [
                'aws_region' => (string) config('messenger.ses_sns.aws.region', ''),
                'aws_profile' => (string) config('messenger.ses_sns.aws.profile', ''),
                'sending_identities' => (array) config('messenger.ses_sns.sending.identities', []),
                'tracking_configuration_sets' => (array) config('messenger.ses_sns.configuration_sets', []),
                'tracking_topic_name' => (string) config('messenger.ses_sns.topic_name', ''),
                'tracking_topic_arn' => (string) config('messenger.ses_sns.topic_arn', ''),
                'tracking_tenant_name' => (string) config('messenger.ses_sns.tenant.name', ''),
                'tracking_callback_endpoint' => (string) config('messenger.ses_sns.callback_endpoint', ''),
                'tracking_event_types' => (array) config('messenger.ses_sns.event_types', []),
                'mail_default_mailer' => (string) config('mail.default', ''),
                'mail_from_address' => (string) config('mail.from.address', ''),
                'mail_from_name' => (string) config('mail.from.name', ''),
                'track_links' => (bool) config('messenger.tracking.track_links', false),
                'inject_pixel' => (bool) config('messenger.tracking.inject_pixel', false),
            ],
            'required_env' => [
                'AWS_DEFAULT_REGION',
                'AWS_ACCESS_KEY_ID',
                'AWS_SECRET_ACCESS_KEY',
                'AWS_SES_IDENTITY_DOMAIN',
                'AWS_SES_MAIL_FROM_DOMAIN',
                'MAIL_MAILER',
                'MAIL_FROM_ADDRESS',
                'MAIL_FROM_NAME',
            ],
        ]);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     ok: bool|null,
     *     error: string|null,
     *     checks: array<int, array{key: string, label: string, ok: bool, details: string}>,
     *     dns_records: array<int, array{name: string, type: string, values: array<int, string>}>
     * }
     */
    protected function resolveSendingStatus(): array
    {
        try {
            $service = app(SesSendingSetupService::class);
            $status = $service->check();

            return [
                'enabled' => true,
                'ok' => $status['ok'],
                'error' => null,
                'checks' => $status['checks'],
                'dns_records' => $status['dns_records'],
                'identities_details' => $status['identities_details'],
            ];
        } catch (Throwable $e) {
            return [
                'enabled' => true,
                'ok' => false,
                'error' => $e->getMessage(),
                'checks' => [],
                'dns_records' => [],
                'identities_details' => [],
            ];
        }
    }

    /**
     * @return array{
     *     enabled: bool,
     *     ok: bool|null,
     *     error: string|null,
     *     configuration: array<string, mixed>,
     *     checks: array<int, array{key: string, label: string, ok: bool, details: string}>,
     *     aws_console: array<string, string>
     * }
     */
    protected function resolveTrackingStatus(): array
    {
        try {
            $service = app(SesSnsSetupService::class);
            $status = $service->check();

            return [
                'enabled' => true,
                'ok' => $status['ok'],
                'error' => null,
                'configuration' => $status['configuration'],
                'checks' => $status['checks'],
                'aws_console' => $status['aws_console'],
            ];
        } catch (Throwable $e) {
            return [
                'enabled' => true,
                'ok' => false,
                'error' => $e->getMessage(),
                'configuration' => [],
                'checks' => [],
                'aws_console' => [],
            ];
        }
    }
}
