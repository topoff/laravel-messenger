<?php

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Topoff\Messenger\Mail\SesTestMail;
use Topoff\Messenger\Services\SesSns\SesEventSimulatorService;

class TestSesSnsEventsCommand extends Command
{
    protected $signature = 'messenger:ses-sns:test-events
        {--from= : Sender email identity}
        {--scenario=* : Scenarios: delivery,bounce,complaint}
        {--configuration-set= : Override configuration set}
        {--tenant= : Override tenant name}
        {--create-message-record : Create a messages row per sent email}
        {--wait=30 : Seconds to wait for tracking updates}
        {--poll-interval=2 : Poll interval in seconds while waiting}';

    protected $description = 'Send SES mailbox simulator events via API and optionally verify tracking_meta updates.';

    /**
     * @var array<string, string>
     */
    protected array $scenarioRecipients = [
        'delivery' => 'success@simulator.amazonses.com',
        'success' => 'success@simulator.amazonses.com',
        'bounce' => 'bounce@simulator.amazonses.com',
        'complaint' => 'complaint@simulator.amazonses.com',
    ];

    public function handle(SesEventSimulatorService $simulator): int
    {
        $fromEmail = $this->resolveFromEmail();
        if ($fromEmail === null) {
            $this->error('Missing sender email. Use --from or configure mail.from.address.');

            return self::FAILURE;
        }

        $configurationSet = $this->resolveConfigurationSet();
        $tenantName = $this->resolveTenantName();
        $scenarios = $this->resolveScenarios();
        if ($scenarios === []) {
            $this->error('No valid scenarios selected. Allowed: delivery,bounce,complaint.');

            return self::FAILURE;
        }

        $createMessageRecord = (bool) $this->option('create-message-record');
        $waitSeconds = max(0, (int) $this->option('wait'));
        $pollInterval = max(1, (int) $this->option('poll-interval'));

        $messageModelClass = config('messenger.models.message');
        $messageIds = [];

        foreach ($scenarios as $scenario) {
            $recipient = $this->scenarioRecipients[$scenario];
            $subject = '[messenger]['.$scenario.'] '.now()->toDateTimeString();
            $body = 'SES simulator scenario: '.$scenario.' ('.Str::uuid()->toString().')';

            $messageId = $simulator->send(
                fromEmail: $fromEmail,
                toEmail: $recipient,
                subject: $subject,
                textBody: $body,
                configurationSetName: $configurationSet,
                tenantName: $tenantName,
                tags: [
                    ['Name' => 'messenger_test', 'Value' => 'true'],
                    ['Name' => 'scenario', 'Value' => $scenario],
                ],
            );

            if ($messageId === '') {
                $this->error('SES sendEmail returned an empty MessageId for scenario: '.$scenario);

                return self::FAILURE;
            }

            $this->line(sprintf('[SENT] %s -> %s (%s)', $scenario, $recipient, $messageId));
            $messageIds[$scenario] = $messageId;

            if ($createMessageRecord) {
                $this->createMessageRecord($messageModelClass, $messageId, $scenario, $recipient, $subject);
                $this->line(sprintf('[DB] created messages row for %s (%s)', $scenario, $messageId));
            }
        }

        if (! $createMessageRecord) {
            $this->info('Sent simulator emails successfully. Use --create-message-record to validate tracking_meta processing.');

            return self::SUCCESS;
        }

        if ($waitSeconds <= 0) {
            $this->warn('Messages created. Waiting skipped (--wait=0), so tracking_meta verification was not performed.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Waiting up to %d seconds for tracking_meta updates...', $waitSeconds));
        $deadline = now()->addSeconds($waitSeconds);
        $allProcessed = false;

        while (now()->lt($deadline)) {
            if ($this->allScenariosProcessed($messageModelClass, $messageIds)) {
                $allProcessed = true;
                break;
            }

            sleep($pollInterval);
        }

        foreach ($messageIds as $scenario => $messageId) {
            /** @var Model|null $message */
            $message = $messageModelClass::query()->where('tracking_message_id', $messageId)->first();
            $meta = (array) data_get($message, 'tracking_meta', []);
            $processed = $this->isScenarioProcessed($scenario, $meta);
            $status = $processed ? 'OK' : 'PENDING';
            $this->line(sprintf('[%s] %s (%s)', $status, $scenario, $messageId));
        }

        if (! $allProcessed) {
            $this->warn('Some scenarios are still pending. Verify SNS callback reachability and queue processing.');

            return self::FAILURE;
        }

        $this->info('All selected scenarios were processed in tracking_meta.');

        return self::SUCCESS;
    }

    protected function resolveFromEmail(): ?string
    {
        $value = trim((string) $this->option('from'));
        if ($value !== '') {
            return $value;
        }

        $identityAddress = trim((string) config('messenger.ses_sns.sending.identities.default.mail_from_address', ''));
        if ($identityAddress !== '') {
            return $identityAddress;
        }

        $configValue = trim((string) config('mail.from.address', ''));

        return $configValue !== '' ? $configValue : null;
    }

    protected function resolveConfigurationSet(): ?string
    {
        $value = trim((string) $this->option('configuration-set'));
        if ($value !== '') {
            return $value;
        }

        $configValue = trim((string) config('messenger.ses_sns.configuration_sets.default.configuration_set', ''));

        return $configValue !== '' ? $configValue : null;
    }

    protected function resolveTenantName(): ?string
    {
        $value = trim((string) $this->option('tenant'));
        if ($value !== '') {
            return $value;
        }

        $configValue = trim((string) config('messenger.ses_sns.tenant.name', ''));

        return $configValue !== '' ? $configValue : null;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveScenarios(): array
    {
        $requested = (array) $this->option('scenario');
        if ($requested === []) {
            $requested = ['delivery', 'bounce', 'complaint'];
        }

        $normalized = array_map(static fn (mixed $scenario): string => strtolower(trim((string) $scenario)), $requested);
        $normalized = array_values(array_filter($normalized, fn (string $scenario): bool => isset($this->scenarioRecipients[$scenario])));

        return array_values(array_unique($normalized));
    }

    /**
     * @param  class-string<Model>  $messageModelClass
     */
    protected function createMessageRecord(string $messageModelClass, string $messageId, string $scenario, string $recipientEmail, string $subject): void
    {
        $messageTypeId = $this->ensureMessageTypeId();

        $messageModelClass::query()->create([
            'message_type_id' => $messageTypeId,
            'tracking_message_id' => $messageId,
            'tracking_recipient_contact' => $recipientEmail,
            'tracking_subject' => $subject,
            'tracking_meta' => [
                'simulator' => true,
                'scenario' => $scenario,
                'created_at' => now()->toIso8601String(),
            ],
        ]);
    }

    protected function ensureMessageTypeId(): int
    {
        $messageTypeModelClass = config('messenger.models.message_type');

        /** @var Model $messageType */
        $messageType = $messageTypeModelClass::query()->firstOrCreate(
            ['notification_class' => SesTestMail::class],
            [
                'single_handler' => null,
                'bulk_handler' => null,
                'direct' => false,
                'dev_bcc' => true,
                'error_stop_send_minutes' => 60,
                'required_sender' => false,
                'required_messagable' => false,
                'required_company_id' => false,
                'required_scheduled' => false,
                'required_text' => false,
                'required_params' => false,
                'bulk_message_line' => null,
            ]
        );

        return (int) $messageType->getAttribute('id');
    }

    /**
     * @param  class-string<Model>  $messageModelClass
     * @param  array<string, string>  $messageIds
     */
    protected function allScenariosProcessed(string $messageModelClass, array $messageIds): bool
    {
        foreach ($messageIds as $scenario => $messageId) {
            /** @var Model|null $message */
            $message = $messageModelClass::query()->where('tracking_message_id', $messageId)->first();
            $meta = (array) data_get($message, 'tracking_meta', []);

            if (! $this->isScenarioProcessed($scenario, $meta)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function isScenarioProcessed(string $scenario, array $meta): bool
    {
        return match ($scenario) {
            'delivery', 'success' => data_get($meta, 'success') === true && data_get($meta, 'sns_message_delivery') !== null,
            'bounce' => data_get($meta, 'sns_message_bounce') !== null,
            'complaint' => data_get($meta, 'sns_message_complaint') !== null,
            default => false,
        };
    }
}
