<?php

namespace Topoff\MailManager\Services\SesSns;

use Topoff\MailManager\Contracts\SesSnsProvisioningApi;

class AwsSesSnsProvisioningApi implements SesSnsProvisioningApi
{
    protected object $sesV2;

    protected object $sns;

    protected object $sts;

    protected object $route53;

    public function __construct()
    {
        $sharedConfig = $this->sharedAwsConfig();
        $sesClientClass = '\\Aws\\SesV2\\SesV2Client';
        $snsClientClass = '\\Aws\\Sns\\SnsClient';
        $stsClientClass = '\\Aws\\Sts\\StsClient';
        $route53ClientClass = '\\Aws\\Route53\\Route53Client';

        if (! class_exists($sesClientClass) || ! class_exists($snsClientClass) || ! class_exists($stsClientClass) || ! class_exists($route53ClientClass)) {
            throw new \RuntimeException('AWS SDK classes not found. Please install aws/aws-sdk-php.');
        }

        $this->sesV2 = new $sesClientClass($sharedConfig);
        $this->sns = new $snsClientClass($sharedConfig);
        $this->sts = new $stsClientClass($sharedConfig);
        $this->route53 = new $route53ClientClass($sharedConfig);
    }

    public function getCallerAccountId(): string
    {
        $result = $this->sts->getCallerIdentity();

        return (string) ($result['Account'] ?? '');
    }

    public function findTopicArnByName(string $topicName): ?string
    {
        $nextToken = null;

        do {
            $result = $this->sns->listTopics(array_filter(['NextToken' => $nextToken]));
            $topics = (array) ($result['Topics'] ?? []);

            foreach ($topics as $topic) {
                $topicArn = (string) ($topic['TopicArn'] ?? '');
                if ($topicArn !== '' && str_ends_with($topicArn, ':'.$topicName)) {
                    return $topicArn;
                }
            }

            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken);

        return null;
    }

    public function createTopic(string $topicName): string
    {
        $result = $this->sns->createTopic(['Name' => $topicName]);

        return (string) ($result['TopicArn'] ?? '');
    }

    public function getTopicAttributes(string $topicArn): array
    {
        $result = $this->sns->getTopicAttributes(['TopicArn' => $topicArn]);

        return (array) ($result['Attributes'] ?? []);
    }

    public function setTopicPolicy(string $topicArn, string $policyJson): void
    {
        $this->sns->setTopicAttributes([
            'TopicArn' => $topicArn,
            'AttributeName' => 'Policy',
            'AttributeValue' => $policyJson,
        ]);
    }

    public function hasHttpsSubscription(string $topicArn, string $endpoint): bool
    {
        return $this->findHttpsSubscriptionArn($topicArn, $endpoint) !== null;
    }

    public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): ?string
    {
        $nextToken = null;

        do {
            $result = $this->sns->listSubscriptionsByTopic(array_filter([
                'TopicArn' => $topicArn,
                'NextToken' => $nextToken,
            ]));

            $subscriptions = (array) ($result['Subscriptions'] ?? []);
            foreach ($subscriptions as $subscription) {
                if (($subscription['Protocol'] ?? null) === 'https' && ($subscription['Endpoint'] ?? null) === $endpoint) {
                    $arn = (string) ($subscription['SubscriptionArn'] ?? '');

                    return $arn !== '' ? $arn : null;
                }
            }

            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken);

        return null;
    }

    public function subscribeHttps(string $topicArn, string $endpoint): void
    {
        $this->sns->subscribe([
            'TopicArn' => $topicArn,
            'Protocol' => 'https',
            'Endpoint' => $endpoint,
        ]);
    }

    public function unsubscribe(string $subscriptionArn): void
    {
        $this->sns->unsubscribe([
            'SubscriptionArn' => $subscriptionArn,
        ]);
    }

    public function deleteTopic(string $topicArn): void
    {
        $this->sns->deleteTopic([
            'TopicArn' => $topicArn,
        ]);
    }

    public function configurationSetExists(string $configurationSetName): bool
    {
        try {
            $this->sesV2->getConfigurationSet(['ConfigurationSetName' => $configurationSetName]);

            return true;
        } catch (\Throwable $e) {
            $errorCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
            if (in_array($errorCode, ['NotFoundException', 'ConfigurationSetDoesNotExistException'], true)) {
                return false;
            }

            throw $e;
        }
    }

    public function createConfigurationSet(string $configurationSetName): void
    {
        $this->sesV2->createConfigurationSet(['ConfigurationSetName' => $configurationSetName]);
    }

    public function getEventDestination(string $configurationSetName, string $eventDestinationName): ?array
    {
        try {
            $result = $this->sesV2->getConfigurationSetEventDestinations([
                'ConfigurationSetName' => $configurationSetName,
            ]);
        } catch (\Throwable $e) {
            $errorCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
            if (in_array($errorCode, ['NotFoundException', 'ConfigurationSetDoesNotExistException'], true)) {
                return null;
            }

            throw $e;
        }

        $destinations = (array) ($result['EventDestinations'] ?? []);
        foreach ($destinations as $destination) {
            if (($destination['Name'] ?? null) === $eventDestinationName) {
                return (array) $destination;
            }
        }

        return null;
    }

    public function upsertEventDestination(
        string $configurationSetName,
        string $eventDestinationName,
        string $topicArn,
        array $eventTypes,
        bool $enabled = true,
    ): void {
        $payload = [
            'ConfigurationSetName' => $configurationSetName,
            'EventDestinationName' => $eventDestinationName,
            'EventDestination' => [
                'Enabled' => $enabled,
                'MatchingEventTypes' => array_values($eventTypes),
                'SnsDestination' => ['TopicArn' => $topicArn],
            ],
        ];

        $existing = $this->getEventDestination($configurationSetName, $eventDestinationName);
        if ($existing === null) {
            $this->sesV2->createConfigurationSetEventDestination($payload);

            return;
        }

        $this->sesV2->updateConfigurationSetEventDestination($payload);
    }

    public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void
    {
        $this->sesV2->deleteConfigurationSetEventDestination([
            'ConfigurationSetName' => $configurationSetName,
            'EventDestinationName' => $eventDestinationName,
        ]);
    }

    public function deleteConfigurationSet(string $configurationSetName): void
    {
        $this->sesV2->deleteConfigurationSet([
            'ConfigurationSetName' => $configurationSetName,
        ]);
    }

    public function tenantExists(string $tenantName): bool
    {
        try {
            $this->sesV2->getTenant(['TenantName' => $tenantName]);

            return true;
        } catch (\Throwable $e) {
            $errorCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
            if ($errorCode === 'NotFoundException') {
                return false;
            }

            throw $e;
        }
    }

    public function createTenant(string $tenantName): void
    {
        $this->sesV2->createTenant([
            'TenantName' => $tenantName,
        ]);
    }

    public function tenantHasResourceAssociation(string $tenantName, string $resourceArn): bool
    {
        $nextToken = null;

        do {
            $params = ['TenantName' => $tenantName];
            if ($nextToken !== null) {
                $params['NextToken'] = $nextToken;
            }

            $result = $this->sesV2->listTenantResources($params);
            $resources = (array) ($result['TenantResources'] ?? []);

            foreach ($resources as $resource) {
                if (($resource['ResourceArn'] ?? null) === $resourceArn) {
                    return true;
                }
            }

            $nextToken = (string) ($result['NextToken'] ?? '');
        } while ($nextToken !== '');

        return false;
    }

    public function associateTenantResource(string $tenantName, string $resourceArn): void
    {
        $this->sesV2->createTenantResourceAssociation([
            'TenantName' => $tenantName,
            'ResourceArn' => $resourceArn,
        ]);
    }

    public function disassociateTenantResource(string $tenantName, string $resourceArn): void
    {
        $this->sesV2->deleteTenantResourceAssociation([
            'TenantName' => $tenantName,
            'ResourceArn' => $resourceArn,
        ]);
    }

    public function getEmailIdentity(string $identity): ?array
    {
        try {
            $result = $this->sesV2->getEmailIdentity([
                'EmailIdentity' => $identity,
            ]);
        } catch (\Throwable $e) {
            $errorCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
            if (in_array($errorCode, ['NotFoundException', 'BadRequestException'], true)) {
                return null;
            }

            throw $e;
        }

        return $result->toArray();
    }

    public function createEmailIdentity(string $identity): array
    {
        $result = $this->sesV2->createEmailIdentity([
            'EmailIdentity' => $identity,
        ]);

        return $result->toArray();
    }

    public function putEmailIdentityMailFromAttributes(
        string $identity,
        string $mailFromDomain,
        string $behaviorOnMxFailure = 'USE_DEFAULT_VALUE',
    ): void {
        $this->sesV2->putEmailIdentityMailFromAttributes([
            'EmailIdentity' => $identity,
            'MailFromDomain' => $mailFromDomain,
            'BehaviorOnMxFailure' => $behaviorOnMxFailure,
        ]);
    }

    public function putEmailIdentityConfigurationSetAttributes(
        string $identity,
        string $configurationSetName,
    ): void {
        $this->sesV2->putEmailIdentityConfigurationSetAttributes([
            'EmailIdentity' => $identity,
            'ConfigurationSetName' => $configurationSetName,
        ]);
    }

    public function findHostedZoneIdByDomain(string $domain): ?string
    {
        $nextMarker = null;
        $domainNormalized = rtrim(strtolower($domain), '.').'.';

        do {
            $result = $this->route53->listHostedZones(array_filter(['Marker' => $nextMarker]));
            $zones = (array) ($result['HostedZones'] ?? []);

            foreach ($zones as $zone) {
                $zoneName = strtolower((string) ($zone['Name'] ?? ''));
                if ($zoneName === $domainNormalized) {
                    $id = (string) ($zone['Id'] ?? '');

                    return str_replace('/hostedzone/', '', $id);
                }
            }

            $nextMarker = $result['IsTruncated'] ? ($result['NextMarker'] ?? null) : null;
        } while ($nextMarker !== null);

        return null;
    }

    public function upsertRoute53Record(
        string $hostedZoneId,
        string $recordName,
        string $recordType,
        array $values,
        int $ttl = 300,
    ): void {
        $resourceRecords = array_map(
            static fn (string $value): array => ['Value' => $value],
            array_values($values)
        );

        $this->route53->changeResourceRecordSets([
            'HostedZoneId' => $hostedZoneId,
            'ChangeBatch' => [
                'Changes' => [[
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'Name' => $recordName,
                        'Type' => strtoupper($recordType),
                        'TTL' => $ttl,
                        'ResourceRecords' => $resourceRecords,
                    ],
                ]],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedAwsConfig(): array
    {
        $region = (string) config('mail-manager.ses_sns.aws.region', 'eu-central-1');
        $profile = config('mail-manager.ses_sns.aws.profile');
        $key = config('mail-manager.ses_sns.aws.key');
        $secret = config('mail-manager.ses_sns.aws.secret');
        $sessionToken = config('mail-manager.ses_sns.aws.session_token');

        $config = [
            'version' => 'latest',
            'region' => $region,
        ];

        if (is_string($profile) && $profile !== '') {
            $config['profile'] = $profile;
        }

        if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
            $config['credentials'] = array_filter([
                'key' => $key,
                'secret' => $secret,
                'token' => is_string($sessionToken) && $sessionToken !== '' ? $sessionToken : null,
            ]);
        }

        return $config;
    }
}
