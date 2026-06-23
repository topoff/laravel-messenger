<?php

use Topoff\Messenger\Contracts\SesSnsProvisioningApi;
use Topoff\Messenger\Services\SesSns\SesSendingSetupService;
use Topoff\Messenger\Tests\Doubles\InMemorySesSnsProvisioningApi;

/**
 * Run check() against a single domain identity with three fixed DKIM tokens
 * and a stubbed DNS resolver, returning the first DKIM CNAME value produced.
 */
function dkimCnameValueFor(string $region, bool $regionalResolves, ?string $override = null): string
{
    config()->set('messenger.ses_sns.aws.region', $region);
    config()->set('messenger.ses_sns.aws.dkim_domain', $override);
    config()->set('messenger.ses_sns.tenant.name', '');
    config()->set('messenger.ses_sns.sending.identities', [
        'default' => [
            'identity_domain' => 'example.com',
            'mail_from_domain' => 'mail.example.com',
            'mail_from_address' => 'noreply@example.com',
        ],
    ]);
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'cs',
            'event_destination' => 'ed',
            'identity' => 'default',
        ],
    ]);

    $fake = new class extends InMemorySesSnsProvisioningApi
    {
        public function getEmailIdentity(string $identity): array
        {
            return [
                'VerifiedForSendingStatus' => false,
                'DkimAttributes' => ['Tokens' => ['aaa', 'bbb', 'ccc'], 'Status' => 'PENDING'],
                'MailFromAttributes' => ['MailFromDomainStatus' => 'PENDING'],
            ];
        }
    };

    $service = new SesSendingSetupService($fake);
    $service->setDkimEndpointResolver(
        fn (string $name): bool => $regionalResolves && str_contains($name, '.dkim.'.$region.'.amazonses.com')
    );

    $record = collect($service->check()['dns_records'])->firstWhere('type', 'CNAME');

    return (string) ($record['values'][0] ?? '');
}

it('builds a region-specific DKIM CNAME when AWS publishes the regional endpoint', function () {
    expect(dkimCnameValueFor('eu-central-2', regionalResolves: true))
        ->toBe('aaa.dkim.eu-central-2.amazonses.com');
});

it('falls back to the global DKIM domain when the regional endpoint does not resolve', function () {
    expect(dkimCnameValueFor('eu-central-1', regionalResolves: false))
        ->toBe('aaa.dkim.amazonses.com');
});

it('lets the dkim_domain config override win over the DNS probe', function () {
    expect(dkimCnameValueFor('eu-central-2', regionalResolves: true, override: 'dkim.eu-west-1.amazonses.com'))
        ->toBe('aaa.dkim.eu-west-1.amazonses.com');
});

it('creates ses domain identity and returns required dns records', function () {
    config()->set('messenger.ses_sns.sending.enabled', true);
    config()->set('messenger.ses_sns.sending.identities', [
        'default' => [
            'identity_domain' => 'example.com',
            'mail_from_domain' => 'mail.example.com',
            'mail_from_address' => 'noreply@example.com',
        ],
    ]);
    config()->set('messenger.ses_sns.aws.region', 'eu-central-1');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.tenant.name', 'topofferten');

    $fake = new class implements SesSnsProvisioningApi
    {
        public bool $identityExists = false;

        public bool $configurationSetExists = false;

        public array $assignedConfigurationSets = [];

        public bool $tenantExists = false;

        /** @var array<int, string> */
        public array $associatedResources = [];

        public array $identityData = [
            'VerifiedForSendingStatus' => false,
            'DkimAttributes' => [
                'Tokens' => ['aaa', 'bbb', 'ccc'],
            ],
            'MailFromAttributes' => [
                'MailFromDomainStatus' => 'PENDING',
            ],
        ];

        public function getCallerAccountId(): string
        {
            return '123';
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
            return $this->configurationSetExists;
        }

        public function createConfigurationSet(string $configurationSetName): void
        {
            $this->configurationSetExists = true;
        }

        public function getEventDestination(string $configurationSetName, string $eventDestinationName): ?array
        {
            return null;
        }

        public function upsertEventDestination(string $configurationSetName, string $eventDestinationName, string $topicArn, array $eventTypes, bool $enabled = true): void {}

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void {}

        public function deleteConfigurationSet(string $configurationSetName): void {}

        public function tenantExists(string $tenantName): bool
        {
            return $this->tenantExists;
        }

        public function createTenant(string $tenantName): void
        {
            $this->tenantExists = true;
        }

        public function tenantHasResourceAssociation(string $tenantName, string $resourceArn): bool
        {
            return in_array($resourceArn, $this->associatedResources, true);
        }

        public function associateTenantResource(string $tenantName, string $resourceArn): void
        {
            $this->associatedResources[] = $resourceArn;
        }

        public function disassociateTenantResource(string $tenantName, string $resourceArn): void
        {
            $this->associatedResources = array_values(array_filter(
                $this->associatedResources,
                static fn (string $arn): bool => $arn !== $resourceArn
            ));
        }

        public function getEmailIdentity(string $identity): ?array
        {
            return $this->identityExists ? $this->identityData : null;
        }

        public function createEmailIdentity(string $identity): array
        {
            $this->identityExists = true;

            return [];
        }

        public function putEmailIdentityMailFromAttributes(string $identity, string $mailFromDomain, string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE'): void {}

        public function putEmailIdentityConfigurationSetAttributes(string $identity, string $configurationSetName): void
        {
            $this->assignedConfigurationSets[] = [
                'identity' => $identity,
                'configuration_set' => $configurationSetName,
            ];
        }

        public function findHostedZoneIdByDomain(string $domain): ?string
        {
            return null;
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void {}

        public function findQueueUrlByName(string $queueName): ?string
        {
            return null;
        }

        public function createQueue(string $queueName): string
        {
            return '';
        }

        public function getQueueArn(string $queueUrl): string
        {
            return '';
        }

        public function setQueueAttributes(string $queueUrl, array $attributes): void {}

        public function hasSqsSubscription(string $topicArn, string $queueArn): bool
        {
            return false;
        }

        public function findSqsSubscriptionArn(string $topicArn, string $queueArn): ?string
        {
            return null;
        }

        public function subscribeSqs(string $topicArn, string $queueArn, bool $rawMessageDelivery = false): void {}

        public function deleteQueue(string $queueUrl): void {}
    };

    $service = new SesSendingSetupService($fake);
    $service->setDkimEndpointResolver(fn (): bool => false); // deterministic, no live DNS
    $result = $service->setup();

    expect($result['ok'])->toBeTrue()
        ->and(count($result['dns_records']))->toBe(6) // 3 DKIM CNAME + 2 MAIL FROM (MX, TXT) + 1 DMARC TXT
        ->and($fake->configurationSetExists)->toBeTrue()
        ->and($fake->assignedConfigurationSets)->toContain([
            'identity' => 'example.com',
            'configuration_set' => 'messenger-tracking',
        ])
        ->and($fake->tenantExists)->toBeTrue()
        ->and($fake->associatedResources)->toContain(
            'arn:aws:ses:eu-central-1:123:identity/example.com',
            'arn:aws:ses:eu-central-1:123:configuration-set/messenger-tracking',
        );
});

it('checks if mail_from_address matches ses identity', function () {
    config()->set('messenger.ses_sns.sending.identities', [
        'default' => [
            'identity_email' => 'sender@example.com',
            'mail_from_address' => 'sender@example.com',
        ],
    ]);
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.tenant.name');

    $fake = new class implements SesSnsProvisioningApi
    {
        public function getCallerAccountId(): string
        {
            return '123';
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

        public function upsertEventDestination(string $configurationSetName, string $eventDestinationName, string $topicArn, array $eventTypes, bool $enabled = true): void {}

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void {}

        public function deleteConfigurationSet(string $configurationSetName): void {}

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

        public function getEmailIdentity(string $identity): array
        {
            return ['VerifiedForSendingStatus' => true];
        }

        public function createEmailIdentity(string $identity): array
        {
            return [];
        }

        public function putEmailIdentityMailFromAttributes(string $identity, string $mailFromDomain, string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE'): void {}

        public function putEmailIdentityConfigurationSetAttributes(string $identity, string $configurationSetName): void {}

        public function findHostedZoneIdByDomain(string $domain): ?string
        {
            return null;
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void {}

        public function findQueueUrlByName(string $queueName): ?string
        {
            return null;
        }

        public function createQueue(string $queueName): string
        {
            return '';
        }

        public function getQueueArn(string $queueUrl): string
        {
            return '';
        }

        public function setQueueAttributes(string $queueUrl, array $attributes): void {}

        public function hasSqsSubscription(string $topicArn, string $queueArn): bool
        {
            return false;
        }

        public function findSqsSubscriptionArn(string $topicArn, string $queueArn): ?string
        {
            return null;
        }

        public function subscribeSqs(string $topicArn, string $queueArn, bool $rawMessageDelivery = false): void {}

        public function deleteQueue(string $queueUrl): void {}
    };

    $service = new SesSendingSetupService($fake);
    $result = $service->check();

    expect($result['ok'])->toBeTrue()
        ->and(collect($result['checks'])->firstWhere('key', 'mail_from_address_matches_identity')['ok'])->toBeTrue();
});

it('check passes when mail.from.address is covered by another identity', function () {
    config()->set('messenger.ses_sns.sending.identities', [
        'default' => [
            'identity_domain' => 'mailer.example.com',
            'mail_from_domain' => 'bounce.mailer.example.com',
            // No explicit mail_from_address — falls back to config('mail.from.address').
        ],
        'app' => [
            'identity_domain' => 'example.com',
            // Verifies example.com so sending from info@example.com is allowed.
        ],
    ]);
    config()->set('mail.from.address', 'info@example.com');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.tenant.name');

    $fake = new class implements SesSnsProvisioningApi
    {
        public function getCallerAccountId(): string
        {
            return '123';
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

        public function upsertEventDestination(string $configurationSetName, string $eventDestinationName, string $topicArn, array $eventTypes, bool $enabled = true): void {}

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void {}

        public function deleteConfigurationSet(string $configurationSetName): void {}

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

        public function getEmailIdentity(string $identity): array
        {
            return ['VerifiedForSendingStatus' => true];
        }

        public function createEmailIdentity(string $identity): array
        {
            return [];
        }

        public function putEmailIdentityMailFromAttributes(string $identity, string $mailFromDomain, string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE'): void {}

        public function putEmailIdentityConfigurationSetAttributes(string $identity, string $configurationSetName): void {}

        public function findHostedZoneIdByDomain(string $domain): ?string
        {
            return null;
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void {}

        public function findQueueUrlByName(string $queueName): ?string
        {
            return null;
        }

        public function createQueue(string $queueName): string
        {
            return '';
        }

        public function getQueueArn(string $queueUrl): string
        {
            return '';
        }

        public function setQueueAttributes(string $queueUrl, array $attributes): void {}

        public function hasSqsSubscription(string $topicArn, string $queueArn): bool
        {
            return false;
        }

        public function findSqsSubscriptionArn(string $topicArn, string $queueArn): ?string
        {
            return null;
        }

        public function subscribeSqs(string $topicArn, string $queueArn, bool $rawMessageDelivery = false): void {}

        public function deleteQueue(string $queueUrl): void {}
    };

    $service = new SesSendingSetupService($fake);
    $result = $service->check();
    $checks = collect($result['checks']);

    // The default identity has no explicit mail_from_address, so it falls back
    // to mail.from.address (info@example.com). This should pass because the
    // 'app' identity covers example.com.
    $defaultCheck = $checks->firstWhere('key', 'mail_from_address_matches_identity_default');
    expect($defaultCheck['ok'])->toBeTrue()
        ->and($defaultCheck['details'])->toContain('covered by identity "example.com"');

    // The app identity also falls back to mail.from.address — matches directly.
    $appCheck = $checks->firstWhere('key', 'mail_from_address_matches_identity_app');
    expect($appCheck['ok'])->toBeTrue();
});
