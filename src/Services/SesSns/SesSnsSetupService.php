<?php

namespace Topoff\Messenger\Services\SesSns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;
use Topoff\Messenger\Contracts\SesSnsProvisioningApi;

class SesSnsSetupService
{
    public function __construct(protected SesSnsProvisioningApi $api) {}

    /**
     * @return array{ok: bool, steps: array<int, array{label: string, ok: bool, details: string}>, status: array<string, mixed>}
     */
    public function setup(): array
    {
        $steps = [];
        $configurationSets = $this->configurationSets();

        $accountId = $this->accountId();
        $topicArn = $this->ensureTopic($steps);
        $this->ensureTopicPolicy($topicArn, $accountId, $configurationSets, $steps);
        $this->ensureHttpsSubscription($topicArn, $steps);

        foreach ($configurationSets as $key => $set) {
            $configSetName = $set['configuration_set'];
            $eventDestName = $set['event_destination'];
            $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';

            $this->ensureConfigurationSet($configSetName, $suffix, $steps);
            $this->ensureTenantConfigurationSetAssociation($accountId, $configSetName, $suffix, $steps);
            $this->ensureEventDestination($configSetName, $eventDestName, $topicArn, $suffix, $steps);
        }

        $status = $this->check();

        return [
            'ok' => $status['ok'],
            'steps' => $steps,
            'status' => $status,
        ];
    }

    /**
     * @return array{ok: bool, configuration: array<string, mixed>, checks: array<int, array{key: string, label: string, ok: bool, details: string}>, aws_console: array<string, string>}
     */
    public function check(): array
    {
        $configurationSets = $this->configurationSets();
        $topicArn = $this->resolveTopicArn();
        $endpoint = $this->callbackEndpoint();
        $eventTypes = $this->eventTypes();

        $checks = [];
        $this->addCheck($checks, 'sns_topic', 'SNS topic exists', $topicArn !== '', $topicArn !== '' ? $topicArn : 'Topic not found');

        $topicAttributes = [];
        if ($topicArn !== '') {
            $topicAttributes = $this->api->getTopicAttributes($topicArn);
            $policyRaw = (string) ($topicAttributes['Policy'] ?? '');
            $policyAllowsSes = $this->topicPolicyAllowsSesPublish($policyRaw);
            $this->addCheck($checks, 'sns_policy', 'SNS topic policy allows SES publish', $policyAllowsSes, $policyAllowsSes ? 'Policy contains ses.amazonaws.com publish statement.' : 'Missing SES publish permission in policy.');

            if ($endpoint !== '') {
                $subscriptionExists = $this->api->hasHttpsSubscription($topicArn, $endpoint);
                $this->addCheck(
                    $checks,
                    'sns_subscription',
                    'HTTPS subscription exists for callback endpoint',
                    $subscriptionExists,
                    $subscriptionExists ? $endpoint : 'Missing HTTPS subscription for: '.$endpoint
                );
            } else {
                $this->addCheck($checks, 'sns_subscription', 'HTTPS subscription endpoint configured', false, 'Callback endpoint is empty.');
            }
        } else {
            $this->addCheck($checks, 'sns_policy', 'SNS topic policy allows SES publish', false, 'Topic missing.');
            $this->addCheck($checks, 'sns_subscription', 'HTTPS subscription exists for callback endpoint', false, 'Topic missing.');
        }

        foreach ($configurationSets as $key => $set) {
            $configSetName = $set['configuration_set'];
            $eventDestName = $set['event_destination'];
            $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';
            $keySuffix = count($configurationSets) > 1 ? "_{$key}" : '';

            $configurationSetExists = $this->api->configurationSetExists($configSetName);
            $this->addCheck($checks, 'ses_configuration_set'.$keySuffix, 'SES configuration set exists'.$suffix, $configurationSetExists, $configSetName);

            if ($configurationSetExists) {
                $eventDestination = $this->api->getEventDestination($configSetName, $eventDestName);
                $destinationExists = $eventDestination !== null;
                $this->addCheck($checks, 'ses_destination_exists'.$keySuffix, 'SES event destination exists'.$suffix, $destinationExists, $destinationExists ? $eventDestName : 'Missing destination');

                if ($destinationExists) {
                    $destinationTopicArn = (string) Arr::get($eventDestination, 'SnsDestination.TopicArn', '');
                    $enabled = (bool) Arr::get($eventDestination, 'Enabled', false);
                    $configuredEventTypes = array_map(strtoupper(...), (array) Arr::get($eventDestination, 'MatchingEventTypes', []));
                    $missingEventTypes = array_values(array_diff($eventTypes, $configuredEventTypes));

                    $this->addCheck($checks, 'ses_destination_topic'.$keySuffix, 'SES destination points to SNS topic'.$suffix, $topicArn !== '' && $destinationTopicArn === $topicArn, $destinationTopicArn);
                    $this->addCheck($checks, 'ses_destination_enabled'.$keySuffix, 'SES destination is enabled'.$suffix, $enabled, $enabled ? 'Enabled' : 'Disabled');
                    $this->addCheck(
                        $checks,
                        'ses_destination_events'.$keySuffix,
                        'SES destination has required event types'.$suffix,
                        $missingEventTypes === [],
                        $missingEventTypes === [] ? implode(', ', $configuredEventTypes) : 'Missing: '.implode(', ', $missingEventTypes)
                    );
                } else {
                    $this->addCheck($checks, 'ses_destination_topic'.$keySuffix, 'SES destination points to SNS topic'.$suffix, false, 'Destination missing.');
                    $this->addCheck($checks, 'ses_destination_enabled'.$keySuffix, 'SES destination is enabled'.$suffix, false, 'Destination missing.');
                    $this->addCheck($checks, 'ses_destination_events'.$keySuffix, 'SES destination has required event types'.$suffix, false, 'Destination missing.');
                }
            } else {
                $this->addCheck($checks, 'ses_destination_exists'.$keySuffix, 'SES event destination exists'.$suffix, false, 'Configuration set missing.');
                $this->addCheck($checks, 'ses_destination_topic'.$keySuffix, 'SES destination points to SNS topic'.$suffix, false, 'Configuration set missing.');
                $this->addCheck($checks, 'ses_destination_enabled'.$keySuffix, 'SES destination is enabled'.$suffix, false, 'Configuration set missing.');
                $this->addCheck($checks, 'ses_destination_events'.$keySuffix, 'SES destination has required event types'.$suffix, false, 'Configuration set missing.');
            }
        }

        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
        $tenantName = $this->tenantName();
        if ($tenantName !== null) {
            $tenantExists = $this->api->tenantExists($tenantName);
            $this->addCheck($checks, 'ses_tenant_exists', 'SES tenant exists', $tenantExists, $tenantName);

            if ($tenantExists) {
                $accountId = $this->accountId();

                foreach ($configurationSets as $key => $set) {
                    $configSetName = $set['configuration_set'];
                    $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';
                    $keySuffix = count($configurationSets) > 1 ? "_{$key}" : '';

                    $configurationSetArn = sprintf('arn:aws:ses:%s:%s:configuration-set/%s', $region, $accountId, $configSetName);
                    $configurationSetAssociated = $this->api->tenantHasResourceAssociation($tenantName, $configurationSetArn);
                    $this->addCheck(
                        $checks,
                        'ses_tenant_configuration_set_association'.$keySuffix,
                        'SES tenant has configuration set association'.$suffix,
                        $configurationSetAssociated,
                        $configurationSetAssociated ? $configurationSetArn : 'Missing association for: '.$configurationSetArn
                    );
                }
            }
        }

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok']);
        $consoleRegion = $region !== '' ? $region : 'eu-central-1';

        return [
            'ok' => $ok,
            'configuration' => [
                'region' => $region,
                'configuration_sets' => $configurationSets,
                'topic_arn' => $topicArn,
                'topic_name' => (string) config('messenger.ses_sns.topic_name', ''),
                'callback_endpoint' => $endpoint,
                'event_types' => $eventTypes,
            ],
            'checks' => $checks,
            'aws_console' => [
                'ses_dashboard' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sesv2/home?region='.$consoleRegion.'#/account',
                'ses_identities' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sesv2/home?region='.$consoleRegion.'#/identities',
                'ses_configuration_sets' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sesv2/home?region='.$consoleRegion.'#/configuration-sets',
                'ses_reputation' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sesv2/home?region='.$consoleRegion.'#/reputation',
                'ses_tenants' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sesv2/home?region='.$consoleRegion.'#/vdm/tenants',
                'sns_topics' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sns/v3/home?region='.$consoleRegion.'#/topics',
                'sns_subscriptions' => 'https://'.$consoleRegion.'.console.aws.amazon.com/sns/v3/home?region='.$consoleRegion.'#/subscriptions',
            ],
        ];
    }

    /**
     * @return array{ok: bool, steps: array<int, array{label: string, ok: bool, details: string}>}
     */
    public function teardown(): array
    {
        $steps = [];
        $configurationSets = $this->configurationSets();
        $topicArn = $this->resolveTopicArn();
        $endpoint = $this->callbackEndpoint();

        if ($topicArn !== '' && $endpoint !== '') {
            $subscriptionArn = $this->api->findHttpsSubscriptionArn($topicArn, $endpoint);
            if (! in_array($subscriptionArn, [null, '', 'PendingConfirmation'], true)) {
                $this->api->unsubscribe($subscriptionArn);
                $steps[] = ['label' => 'SNS HTTPS subscription', 'ok' => true, 'details' => 'Removed: '.$subscriptionArn];
            } else {
                $steps[] = ['label' => 'SNS HTTPS subscription', 'ok' => true, 'details' => 'Nothing to remove.'];
            }
        } else {
            $steps[] = ['label' => 'SNS HTTPS subscription', 'ok' => true, 'details' => 'Skipped: missing topic or endpoint.'];
        }

        try {
            foreach ($configurationSets as $key => $set) {
                $configSetName = $set['configuration_set'];
                $eventDestName = $set['event_destination'];
                $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';

                if ($this->api->configurationSetExists($configSetName)) {
                    $eventDestinationData = $this->api->getEventDestination($configSetName, $eventDestName);
                    if ($eventDestinationData !== null) {
                        $this->api->deleteEventDestination($configSetName, $eventDestName);
                        $steps[] = ['label' => 'SES event destination'.$suffix, 'ok' => true, 'details' => 'Removed: '.$eventDestName];
                    } else {
                        $steps[] = ['label' => 'SES event destination'.$suffix, 'ok' => true, 'details' => 'Nothing to remove.'];
                    }

                    $this->removeTenantConfigurationSetAssociation($configSetName, $suffix, $steps);
                    $this->api->deleteConfigurationSet($configSetName);
                    $steps[] = ['label' => 'SES configuration set'.$suffix, 'ok' => true, 'details' => 'Removed: '.$configSetName];
                } else {
                    $steps[] = ['label' => 'SES event destination'.$suffix, 'ok' => true, 'details' => 'Skipped: configuration set missing.'];
                    $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Skipped: configuration set missing.'];
                    $steps[] = ['label' => 'SES configuration set'.$suffix, 'ok' => true, 'details' => 'Nothing to remove.'];
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to remove SES resources: '.$e->getMessage(), $e->getCode(), previous: $e);
        }

        if ($topicArn !== '') {
            try {
                $this->api->deleteTopic($topicArn);
                $steps[] = ['label' => 'SNS topic', 'ok' => true, 'details' => 'Removed: '.$topicArn];
            } catch (Throwable $e) {
                throw new RuntimeException('Failed to remove SNS topic: '.$e->getMessage(), $e->getCode(), previous: $e);
            }
        } else {
            $steps[] = ['label' => 'SNS topic', 'ok' => true, 'details' => 'Nothing to remove.'];
        }

        return [
            'ok' => true,
            'steps' => $steps,
        ];
    }

    /**
     * @return array<string, array{configuration_set: string, event_destination: string}>
     */
    protected function configurationSets(): array
    {
        $sets = (array) config('messenger.ses_sns.configuration_sets', []);

        if ($sets === []) {
            throw new RuntimeException('No configuration sets configured. Set messenger.ses_sns.configuration_sets.');
        }

        return $sets;
    }

    /**
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function ensureTopic(array &$steps): string
    {
        $topicArn = $this->resolveTopicArn();

        if ($topicArn !== '') {
            $steps[] = ['label' => 'SNS topic', 'ok' => true, 'details' => 'Using existing topic: '.$topicArn];

            return $topicArn;
        }

        if (! (bool) config('messenger.ses_sns.create_topic_if_missing', true)) {
            throw new RuntimeException('SNS topic does not exist and create_topic_if_missing is false.');
        }

        $topicName = (string) config('messenger.ses_sns.topic_name', '');
        if ($topicName === '') {
            throw new RuntimeException('messenger.ses_sns.topic_name is empty.');
        }

        $createdArn = $this->api->createTopic($topicName);
        if ($createdArn === '') {
            throw new RuntimeException('Failed to create SNS topic.');
        }

        $steps[] = ['label' => 'SNS topic', 'ok' => true, 'details' => 'Created topic: '.$createdArn];

        return $createdArn;
    }

    /**
     * @param  array<string, array{configuration_set: string, event_destination: string}>  $configurationSets
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function ensureTopicPolicy(string $topicArn, string $accountId, array $configurationSets, array &$steps): void
    {
        if (! (bool) config('messenger.ses_sns.set_topic_policy', true)) {
            $steps[] = ['label' => 'SNS topic policy', 'ok' => true, 'details' => 'Skipped by configuration.'];

            return;
        }

        $policy = json_encode($this->buildTopicPolicy($topicArn, $accountId, $configurationSets), JSON_THROW_ON_ERROR);
        $this->api->setTopicPolicy($topicArn, $policy);

        $steps[] = ['label' => 'SNS topic policy', 'ok' => true, 'details' => 'Policy updated for SES publish permissions.'];
    }

    /**
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function ensureHttpsSubscription(string $topicArn, array &$steps): void
    {
        if (! (bool) config('messenger.ses_sns.create_https_subscription_if_missing', true)) {
            $steps[] = ['label' => 'SNS HTTPS subscription', 'ok' => true, 'details' => 'Skipped by configuration.'];

            return;
        }

        $endpoint = $this->callbackEndpoint();
        if ($endpoint === '') {
            throw new RuntimeException('Callback endpoint is empty. Configure messenger.ses_sns.callback_endpoint or APP_URL.');
        }

        if (! $this->isPublicHttpsEndpoint($endpoint)) {
            throw new RuntimeException(
                'Callback endpoint is not publicly reachable via HTTPS for AWS SNS: '.$endpoint.
                '. Use a public HTTPS URL in messenger.ses_sns.callback_endpoint or set create_https_subscription_if_missing=false.'
            );
        }

        if ($this->api->hasHttpsSubscription($topicArn, $endpoint)) {
            $steps[] = ['label' => 'SNS HTTPS subscription', 'ok' => true, 'details' => 'Already subscribed: '.$endpoint];

            return;
        }

        try {
            $this->api->subscribeHttps($topicArn, $endpoint);
        } catch (Throwable $e) {
            throw new RuntimeException('SNS could not subscribe endpoint "'.$endpoint.'". AWS requires a publicly reachable HTTPS endpoint. Original error: '.$e->getMessage(), $e->getCode(), previous: $e);
        }

        $steps[] = ['label' => 'SNS HTTPS subscription', 'ok' => true, 'details' => 'Subscription requested: '.$endpoint];
    }

    /**
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function ensureConfigurationSet(string $configSetName, string $suffix, array &$steps): void
    {
        if ($this->api->configurationSetExists($configSetName)) {
            $steps[] = ['label' => 'SES configuration set'.$suffix, 'ok' => true, 'details' => 'Already exists: '.$configSetName];

            return;
        }

        $this->api->createConfigurationSet($configSetName);
        $steps[] = ['label' => 'SES configuration set'.$suffix, 'ok' => true, 'details' => 'Created: '.$configSetName];
    }

    /**
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function ensureEventDestination(string $configSetName, string $eventDestName, string $topicArn, string $suffix, array &$steps): void
    {
        $this->api->upsertEventDestination(
            $configSetName,
            $eventDestName,
            $topicArn,
            $this->eventTypes(),
            (bool) config('messenger.ses_sns.enable_event_destination', true),
        );

        $steps[] = ['label' => 'SES event destination'.$suffix, 'ok' => true, 'details' => 'Upserted with SNS topic: '.$topicArn];
    }

    protected function resolveTopicArn(): string
    {
        $configuredTopicArn = (string) config('messenger.ses_sns.topic_arn', '');
        if ($configuredTopicArn !== '') {
            return $configuredTopicArn;
        }

        $topicName = (string) config('messenger.ses_sns.topic_name', '');
        if ($topicName === '') {
            return '';
        }

        return (string) $this->api->findTopicArnByName($topicName);
    }

    protected function accountId(): string
    {
        $configuredAccountId = (string) config('messenger.ses_sns.aws.account_id', '');
        if ($configuredAccountId !== '') {
            return $configuredAccountId;
        }

        return $this->api->getCallerAccountId();
    }

    protected function callbackEndpoint(): string
    {
        $configuredEndpoint = (string) config('messenger.ses_sns.callback_endpoint', '');
        if ($configuredEndpoint !== '') {
            return $configuredEndpoint;
        }

        if (! Route::has('messenger.tracking.sns')) {
            return '';
        }

        return route('messenger.tracking.sns');
    }

    /**
     * @return array<int, string>
     */
    protected function eventTypes(): array
    {
        $eventTypes = (array) config('messenger.ses_sns.event_types', ['SEND', 'REJECT', 'BOUNCE', 'COMPLAINT', 'DELIVERY']);

        return array_values(array_unique(array_map(static fn (mixed $type): string => strtoupper((string) $type), $eventTypes)));
    }

    protected function tenantName(): ?string
    {
        $value = trim((string) config('messenger.ses_sns.tenant.name', ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function ensureTenantConfigurationSetAssociation(string $accountId, string $configSetName, string $suffix, array &$steps): void
    {
        $tenantName = $this->tenantName();
        if ($tenantName === null) {
            $steps[] = ['label' => 'SES tenant'.$suffix, 'ok' => true, 'details' => 'Skipped (not configured).'];
            $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Skipped (tenant not configured).'];

            return;
        }

        if (! $this->api->tenantExists($tenantName)) {
            $this->api->createTenant($tenantName);
            $steps[] = ['label' => 'SES tenant'.$suffix, 'ok' => true, 'details' => 'Created: '.$tenantName];
        } else {
            $steps[] = ['label' => 'SES tenant'.$suffix, 'ok' => true, 'details' => 'Already exists: '.$tenantName];
        }

        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
        $configurationSetArn = sprintf('arn:aws:ses:%s:%s:configuration-set/%s', $region, $accountId, $configSetName);
        if (! $this->api->tenantHasResourceAssociation($tenantName, $configurationSetArn)) {
            $this->api->associateTenantResource($tenantName, $configurationSetArn);
            $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Associated: '.$configurationSetArn];
        } else {
            $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Already associated: '.$configurationSetArn];
        }
    }

    /**
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function removeTenantConfigurationSetAssociation(string $configurationSet, string $suffix, array &$steps): void
    {
        $tenantName = $this->tenantName();
        if ($tenantName === null) {
            $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Skipped (tenant not configured).'];

            return;
        }

        if (! $this->api->tenantExists($tenantName)) {
            $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Skipped (tenant missing).'];

            return;
        }

        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
        $accountId = $this->accountId();
        $configurationSetArn = sprintf('arn:aws:ses:%s:%s:configuration-set/%s', $region, $accountId, $configurationSet);

        if (! $this->api->tenantHasResourceAssociation($tenantName, $configurationSetArn)) {
            $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Nothing to remove.'];

            return;
        }

        $this->api->disassociateTenantResource($tenantName, $configurationSetArn);
        $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Removed: '.$configurationSetArn];
    }

    /**
     * @param  array<string, array{configuration_set: string, event_destination: string}>  $configurationSets
     * @return array<string, mixed>
     */
    protected function buildTopicPolicy(string $topicArn, string $accountId, array $configurationSets): array
    {
        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');

        $configurationSetArns = array_values(array_map(
            static fn (array $set): string => "arn:aws:ses:{$region}:{$accountId}:configuration-set/{$set['configuration_set']}",
            $configurationSets,
        ));

        $sourceArnCondition = count($configurationSetArns) === 1 ? $configurationSetArns[0] : $configurationSetArns;

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'AllowSesPublish',
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'ses.amazonaws.com'],
                    'Action' => 'SNS:Publish',
                    'Resource' => $topicArn,
                    'Condition' => [
                        'StringEquals' => ['AWS:SourceAccount' => $accountId],
                        'ArnLike' => ['AWS:SourceArn' => $sourceArnCondition],
                    ],
                ],
                [
                    'Sid' => 'AllowAccountAdministration',
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => "arn:aws:iam::{$accountId}:root"],
                    'Action' => [
                        'SNS:GetTopicAttributes',
                        'SNS:SetTopicAttributes',
                        'SNS:Subscribe',
                        'SNS:ListSubscriptionsByTopic',
                        'SNS:Publish',
                    ],
                    'Resource' => $topicArn,
                ],
            ],
        ];
    }

    protected function topicPolicyAllowsSesPublish(string $policyRaw): bool
    {
        if ($policyRaw === '') {
            return false;
        }

        $policy = json_decode($policyRaw, true);
        if (! is_array($policy)) {
            return false;
        }

        $statements = $policy['Statement'] ?? [];
        if (! is_array($statements)) {
            return false;
        }

        foreach ($statements as $statement) {
            if (! is_array($statement)) {
                continue;
            }

            $servicePrincipal = data_get($statement, 'Principal.Service');
            $action = data_get($statement, 'Action');

            if ($servicePrincipal === 'ses.amazonaws.com' && ($action === 'SNS:Publish' || (is_array($action) && in_array('SNS:Publish', $action, true)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{key: string, label: string, ok: bool, details: string}>  $checks
     */
    protected function addCheck(array &$checks, string $key, string $label, bool $ok, string $details): void
    {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'details' => $details,
        ];
    }

    protected function isPublicHttpsEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        return ! (str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost'));
    }
}
