<?php

use Topoff\Messenger\Contracts\SesSnsProvisioningApi;
use Topoff\Messenger\Services\SesSns\SesSnsSetupService;

it('provisions missing ses/sns resources and returns green status', function () {
    config()->set('messenger.ses_sns.enabled', true);
    config()->set('messenger.ses_sns.aws.region', 'eu-central-1');
    config()->set('messenger.ses_sns.topic_name', 'messenger-events');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.callback_endpoint', 'https://messenger-demo.ngrok-free.app/email/sns');
    config()->set('messenger.ses_sns.event_types', ['SEND', 'BOUNCE', 'COMPLAINT', 'DELIVERY']);
    config()->set('messenger.ses_sns.tenant.name');

    $fake = new class implements SesSnsProvisioningApi
    {
        public string $accountId = '123456789012';

        public ?string $topicArn = null;

        public array $topicAttributes = [];

        public array $subscriptions = [];

        public bool $configurationSetExists = false;

        public ?array $eventDestination = null;

        public function getCallerAccountId(): string
        {
            return $this->accountId;
        }

        public function findTopicArnByName(string $topicName): ?string
        {
            return $this->topicArn;
        }

        public function createTopic(string $topicName): string
        {
            $this->topicArn = 'arn:aws:sns:eu-central-1:123456789012:'.$topicName;

            return $this->topicArn;
        }

        public function getTopicAttributes(string $topicArn): array
        {
            return $this->topicAttributes;
        }

        public function setTopicPolicy(string $topicArn, string $policyJson): void
        {
            $this->topicAttributes['Policy'] = $policyJson;
        }

        public function hasHttpsSubscription(string $topicArn, string $endpoint): bool
        {
            return in_array($endpoint, $this->subscriptions, true);
        }

        public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): ?string
        {
            return in_array($endpoint, $this->subscriptions, true) ? 'arn:aws:sns:eu-central-1:123456789012:sub/example' : null;
        }

        public function subscribeHttps(string $topicArn, string $endpoint): void
        {
            $this->subscriptions[] = $endpoint;
        }

        public function unsubscribe(string $subscriptionArn): void
        {
            $this->subscriptions = [];
        }

        public function deleteTopic(string $topicArn): void
        {
            $this->topicArn = null;
        }

        public function configurationSetExists(string $configurationSetName): bool
        {
            return $this->configurationSetExists;
        }

        public function createConfigurationSet(string $configurationSetName): void
        {
            $this->configurationSetExists = true;
        }

        public function getEventDestination(string $configurationSetName, string $eventDestinationName): ?array
        {
            return $this->eventDestination;
        }

        public function upsertEventDestination(
            string $configurationSetName,
            string $eventDestinationName,
            string $topicArn,
            array $eventTypes,
            bool $enabled = true,
        ): void {
            $this->eventDestination = [
                'Name' => $eventDestinationName,
                'Enabled' => $enabled,
                'MatchingEventTypes' => $eventTypes,
                'SnsDestination' => ['TopicArn' => $topicArn],
            ];
        }

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void
        {
            $this->eventDestination = null;
        }

        public function deleteConfigurationSet(string $configurationSetName): void
        {
            $this->configurationSetExists = false;
        }

        public function getEmailIdentity(string $identity): ?array
        {
            return null;
        }

        public function createEmailIdentity(string $identity): array
        {
            return [];
        }

        public function putEmailIdentityMailFromAttributes(string $identity, string $mailFromDomain, string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE'): void {}

        public function putEmailIdentityConfigurationSetAttributes(string $identity, string $configurationSetName): void {}

        public function tenantExists(string $tenantName): bool
        {
            return false;
        }

        public function createTenant(string $tenantName): void {}

        public function tenantHasResourceAssociation(string $tenantName, string $resourceArn): bool
        {
            return false;
        }

        public function associateTenantResource(string $tenantName, string $resourceArn): void {}

        public function disassociateTenantResource(string $tenantName, string $resourceArn): void {}

        public function findHostedZoneIdByDomain(string $domain): ?string
        {
            return null;
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void {}
    };

    $service = new SesSnsSetupService($fake);
    $setupResult = $service->setup();
    $status = $service->check();

    expect($setupResult['ok'])->toBeTrue()
        ->and($status['ok'])->toBeTrue()
        ->and($fake->topicArn)->not->toBeNull()
        ->and($fake->configurationSetExists)->toBeTrue()
        ->and($fake->eventDestination)->not->toBeNull()
        ->and($fake->subscriptions)->toContain('https://messenger-demo.ngrok-free.app/email/sns');
});

it('returns failing checks when topic is missing', function () {
    config()->set('messenger.ses_sns.enabled', true);
    config()->set('messenger.ses_sns.topic_name', 'messenger-events');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.callback_endpoint', 'https://backend.example.test/email/sns');

    $fake = new class implements SesSnsProvisioningApi
    {
        public function getCallerAccountId(): string
        {
            return '123456789012';
        }

        public function findTopicArnByName(string $topicName): ?string
        {
            return null;
        }

        public function createTopic(string $topicName): string
        {
            return '';
        }

        public function getTopicAttributes(string $topicArn): array
        {
            return [];
        }

        public function setTopicPolicy(string $topicArn, string $policyJson): void {}

        public function hasHttpsSubscription(string $topicArn, string $endpoint): bool
        {
            return false;
        }

        public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): ?string
        {
            return null;
        }

        public function subscribeHttps(string $topicArn, string $endpoint): void {}

        public function unsubscribe(string $subscriptionArn): void {}

        public function deleteTopic(string $topicArn): void {}

        public function configurationSetExists(string $configurationSetName): bool
        {
            return false;
        }

        public function createConfigurationSet(string $configurationSetName): void {}

        public function getEventDestination(string $configurationSetName, string $eventDestinationName): ?array
        {
            return null;
        }

        public function upsertEventDestination(
            string $configurationSetName,
            string $eventDestinationName,
            string $topicArn,
            array $eventTypes,
            bool $enabled = true,
        ): void {}

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void {}

        public function deleteConfigurationSet(string $configurationSetName): void {}

        public function getEmailIdentity(string $identity): ?array
        {
            return null;
        }

        public function createEmailIdentity(string $identity): array
        {
            return [];
        }

        public function putEmailIdentityMailFromAttributes(string $identity, string $mailFromDomain, string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE'): void {}

        public function putEmailIdentityConfigurationSetAttributes(string $identity, string $configurationSetName): void {}

        public function tenantExists(string $tenantName): bool
        {
            return false;
        }

        public function createTenant(string $tenantName): void {}

        public function tenantHasResourceAssociation(string $tenantName, string $resourceArn): bool
        {
            return false;
        }

        public function associateTenantResource(string $tenantName, string $resourceArn): void {}

        public function disassociateTenantResource(string $tenantName, string $resourceArn): void {}

        public function findHostedZoneIdByDomain(string $domain): ?string
        {
            return null;
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void {}
    };

    $service = new SesSnsSetupService($fake);
    $status = $service->check();

    expect($status['ok'])->toBeFalse()
        ->and(collect($status['checks'])->firstWhere('key', 'sns_topic')['ok'])->toBeFalse();
});

it('tears down existing ses/sns resources', function () {
    config()->set('messenger.ses_sns.enabled', true);
    config()->set('messenger.ses_sns.aws.region', 'eu-central-1');
    config()->set('messenger.ses_sns.aws.account_id', '123456789012');
    config()->set('messenger.ses_sns.tenant.name', 'tenant-a');
    config()->set('messenger.ses_sns.topic_name', 'messenger-events');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.callback_endpoint', 'https://backend.example.test/email/sns');

    $fake = new class implements SesSnsProvisioningApi
    {
        public ?string $topicArn = 'arn:aws:sns:eu-central-1:123456789012:messenger-events';

        public bool $configurationSetExists = true;

        public ?array $eventDestination = ['Name' => 'messenger-sns'];

        public array $subscriptions = ['https://backend.example.test/email/sns'];

        public array $associatedResources = ['arn:aws:ses:eu-central-1:123456789012:configuration-set/messenger-tracking'];

        public function getCallerAccountId(): string
        {
            return '123456789012';
        }

        public function findTopicArnByName(string $topicName): ?string
        {
            return $this->topicArn;
        }

        public function createTopic(string $topicName): string
        {
            return $this->topicArn ?? '';
        }

        public function getTopicAttributes(string $topicArn): array
        {
            return [];
        }

        public function setTopicPolicy(string $topicArn, string $policyJson): void {}

        public function hasHttpsSubscription(string $topicArn, string $endpoint): bool
        {
            return in_array($endpoint, $this->subscriptions, true);
        }

        public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): ?string
        {
            return in_array($endpoint, $this->subscriptions, true) ? 'arn:sub' : null;
        }

        public function subscribeHttps(string $topicArn, string $endpoint): void {}

        public function unsubscribe(string $subscriptionArn): void
        {
            $this->subscriptions = [];
        }

        public function deleteTopic(string $topicArn): void
        {
            $this->topicArn = null;
        }

        public function configurationSetExists(string $configurationSetName): bool
        {
            return $this->configurationSetExists;
        }

        public function createConfigurationSet(string $configurationSetName): void {}

        public function getEventDestination(string $configurationSetName, string $eventDestinationName): ?array
        {
            return $this->eventDestination;
        }

        public function upsertEventDestination(string $configurationSetName, string $eventDestinationName, string $topicArn, array $eventTypes, bool $enabled = true): void {}

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void
        {
            $this->eventDestination = null;
        }

        public function deleteConfigurationSet(string $configurationSetName): void
        {
            $this->configurationSetExists = false;
        }

        public function getEmailIdentity(string $identity): ?array
        {
            return null;
        }

        public function createEmailIdentity(string $identity): array
        {
            return [];
        }

        public function putEmailIdentityMailFromAttributes(string $identity, string $mailFromDomain, string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE'): void {}

        public function putEmailIdentityConfigurationSetAttributes(string $identity, string $configurationSetName): void {}

        public function tenantExists(string $tenantName): bool
        {
            return true;
        }

        public function createTenant(string $tenantName): void {}

        public function tenantHasResourceAssociation(string $tenantName, string $resourceArn): bool
        {
            return in_array($resourceArn, $this->associatedResources, true);
        }

        public function associateTenantResource(string $tenantName, string $resourceArn): void {}

        public function disassociateTenantResource(string $tenantName, string $resourceArn): void
        {
            $this->associatedResources = array_values(array_filter(
                $this->associatedResources,
                static fn (string $arn): bool => $arn !== $resourceArn
            ));
        }

        public function findHostedZoneIdByDomain(string $domain): ?string
        {
            return null;
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void {}
    };

    $service = new SesSnsSetupService($fake);
    $result = $service->teardown();

    expect($result['ok'])->toBeTrue()
        ->and($fake->subscriptions)->toBe([])
        ->and($fake->eventDestination)->toBeNull()
        ->and($fake->configurationSetExists)->toBeFalse()
        ->and($fake->associatedResources)->toBe([])
        ->and($fake->topicArn)->toBeNull();
});
