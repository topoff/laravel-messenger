<?php

namespace Topoff\Messenger\Contracts;

interface SesSnsProvisioningApi
{
    public function getCallerAccountId(): string;

    public function findTopicArnByName(string $topicName): ?string;

    public function createTopic(string $topicName): string;

    /**
     * @return array<string, mixed>
     */
    public function getTopicAttributes(string $topicArn): array;

    public function setTopicPolicy(string $topicArn, string $policyJson): void;

    public function hasHttpsSubscription(string $topicArn, string $endpoint): bool;

    public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): ?string;

    public function subscribeHttps(string $topicArn, string $endpoint): void;

    public function unsubscribe(string $subscriptionArn): void;

    public function deleteTopic(string $topicArn): void;

    public function configurationSetExists(string $configurationSetName): bool;

    public function createConfigurationSet(string $configurationSetName): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getEventDestination(string $configurationSetName, string $eventDestinationName): ?array;

    /**
     * @param  array<int, string>  $eventTypes
     */
    public function upsertEventDestination(
        string $configurationSetName,
        string $eventDestinationName,
        string $topicArn,
        array $eventTypes,
        bool $enabled = true,
    ): void;

    public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void;

    public function deleteConfigurationSet(string $configurationSetName): void;

    public function tenantExists(string $tenantName): bool;

    public function createTenant(string $tenantName): void;

    public function tenantHasResourceAssociation(string $tenantName, string $resourceArn): bool;

    public function associateTenantResource(string $tenantName, string $resourceArn): void;

    public function disassociateTenantResource(string $tenantName, string $resourceArn): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getEmailIdentity(string $identity): ?array;

    /**
     * @return array<string, mixed>
     */
    public function createEmailIdentity(string $identity): array;

    public function putEmailIdentityMailFromAttributes(
        string $identity,
        string $mailFromDomain,
        string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE',
    ): void;

    public function putEmailIdentityConfigurationSetAttributes(
        string $identity,
        string $configurationSetName,
    ): void;

    public function findHostedZoneIdByDomain(string $domain): ?string;

    /**
     * @param  array<int, string>  $values
     */
    public function upsertRoute53Record(
        string $hostedZoneId,
        string $recordName,
        string $recordType,
        array $values,
        int $ttl = 300,
    ): void;
}
