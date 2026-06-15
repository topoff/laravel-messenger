<?php

declare(strict_types=1);

namespace Topoff\Messenger\Tests\Doubles;

use Topoff\Messenger\Contracts\SesSnsProvisioningApi;

/**
 * In-memory implementation of the provisioning API for exercising the SQS
 * transport branches of SesSnsSetupService without hitting AWS.
 */
class InMemorySesSnsProvisioningApi implements SesSnsProvisioningApi
{
    public string $accountId = '123456789012';

    public ?string $topicArn = null;

    /** @var array<string, mixed> */
    public array $topicAttributes = [];

    public bool $configurationSetExists = false;

    /** @var array<string, mixed>|null */
    public ?array $eventDestination = null;

    /** @var array<string, string> queueName => queueUrl */
    public array $queues = [];

    /** @var array<string, array<string, string>> queueUrl => attributes */
    public array $queueAttributes = [];

    /** @var array<int, array{topic: string, endpoint: string, raw: bool}> */
    public array $sqsSubscriptions = [];

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
        return $this->topicArn = 'arn:aws:sns:eu-central-1:'.$this->accountId.':'.$topicName;
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
        return false;
    }

    public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): ?string
    {
        return null;
    }

    public function subscribeHttps(string $topicArn, string $endpoint): void {}

    public function unsubscribe(string $subscriptionArn): void
    {
        $this->sqsSubscriptions = array_values(array_filter(
            $this->sqsSubscriptions,
            fn (array $sub): bool => $this->subscriptionArn($sub) !== $subscriptionArn,
        ));
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

    public function upsertEventDestination(string $configurationSetName, string $eventDestinationName, string $topicArn, array $eventTypes, bool $enabled = true): void
    {
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

    public function findQueueUrlByName(string $queueName): ?string
    {
        return $this->queues[$queueName] ?? null;
    }

    public function createQueue(string $queueName): string
    {
        return $this->queues[$queueName] = 'https://sqs.eu-central-1.amazonaws.com/'.$this->accountId.'/'.$queueName;
    }

    public function getQueueArn(string $queueUrl): string
    {
        $name = basename($queueUrl);

        return 'arn:aws:sqs:eu-central-1:'.$this->accountId.':'.$name;
    }

    public function setQueueAttributes(string $queueUrl, array $attributes): void
    {
        $this->queueAttributes[$queueUrl] = array_merge($this->queueAttributes[$queueUrl] ?? [], $attributes);
    }

    public function hasSqsSubscription(string $topicArn, string $queueArn): bool
    {
        return $this->findSqsSubscriptionArn($topicArn, $queueArn) !== null;
    }

    public function findSqsSubscriptionArn(string $topicArn, string $queueArn): ?string
    {
        foreach ($this->sqsSubscriptions as $sub) {
            if ($sub['topic'] === $topicArn && $sub['endpoint'] === $queueArn) {
                return $this->subscriptionArn($sub);
            }
        }

        return null;
    }

    public function subscribeSqs(string $topicArn, string $queueArn, bool $rawMessageDelivery = false): void
    {
        $this->sqsSubscriptions[] = ['topic' => $topicArn, 'endpoint' => $queueArn, 'raw' => $rawMessageDelivery];
    }

    public function deleteQueue(string $queueUrl): void
    {
        $this->queues = array_filter($this->queues, fn (string $url): bool => $url !== $queueUrl);
        unset($this->queueAttributes[$queueUrl]);
    }

    /**
     * @param  array{topic: string, endpoint: string, raw: bool}  $sub
     */
    protected function subscriptionArn(array $sub): string
    {
        return $sub['topic'].':sub/'.md5($sub['endpoint']);
    }
}
