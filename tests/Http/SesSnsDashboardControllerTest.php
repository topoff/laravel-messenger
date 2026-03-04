<?php

use Illuminate\Support\Facades\URL;
use Topoff\Messenger\Contracts\SesSnsProvisioningApi;

it('renders the ses sns dashboard with setup guidance', function () {
    $url = URL::temporarySignedRoute('messenger.ses-sns.dashboard', now()->addMinutes(10));

    $this->get($url)
        ->assertOk()
        ->assertSee('Amazon SES + SNS Dashboard')
        ->assertSee('Setup Commands')
        ->assertSee('messenger:ses-sns:setup-sending')
        ->assertSee('messenger:ses-sns:setup-tracking')
        ->assertSee('Required Environment Variables');
});

it('renders healthy sending and tracking checks on the ses sns dashboard', function () {
    config()->set('messenger.ses_sns.enabled', true);
    config()->set('messenger.ses_sns.sending.enabled', true);
    config()->set('messenger.ses_sns.aws.region', 'eu-central-1');
    config()->set('messenger.ses_sns.topic_name', 'messenger-events');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.callback_endpoint', 'https://backend.example.test/email/sns');
    config()->set('messenger.ses_sns.event_types', ['SEND', 'BOUNCE', 'COMPLAINT', 'DELIVERY']);
    config()->set('messenger.ses_sns.sending.identities', [
        'default' => [
            'identity_domain' => 'example.com',
            'mail_from_domain' => 'mail.example.com',
            'mail_from_address' => 'noreply@example.com',
        ],
    ]);

    app()->bind(SesSnsProvisioningApi::class, fn (): SesSnsProvisioningApi => new class implements SesSnsProvisioningApi
    {
        public function getCallerAccountId(): string
        {
            return '123456789012';
        }

        public function findTopicArnByName(string $topicName): string
        {
            return 'arn:aws:sns:eu-central-1:123456789012:messenger-events';
        }

        public function createTopic(string $topicName): string
        {
            return 'arn:aws:sns:eu-central-1:123456789012:messenger-events';
        }

        public function getTopicAttributes(string $topicArn): array
        {
            return [
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Allow',
                        'Principal' => ['Service' => 'ses.amazonaws.com'],
                        'Action' => 'SNS:Publish',
                        'Resource' => $topicArn,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        public function setTopicPolicy(string $topicArn, string $policyJson): void {}

        public function hasHttpsSubscription(string $topicArn, string $endpoint): bool
        {
            return true;
        }

        public function findHttpsSubscriptionArn(string $topicArn, string $endpoint): string
        {
            return 'arn:aws:sns:eu-central-1:123456789012:sub/example';
        }

        public function subscribeHttps(string $topicArn, string $endpoint): void {}

        public function unsubscribe(string $subscriptionArn): void {}

        public function deleteTopic(string $topicArn): void {}

        public function configurationSetExists(string $configurationSetName): bool
        {
            return true;
        }

        public function createConfigurationSet(string $configurationSetName): void {}

        public function getEventDestination(string $configurationSetName, string $eventDestinationName): array
        {
            return [
                'Name' => $eventDestinationName,
                'Enabled' => true,
                'MatchingEventTypes' => ['SEND', 'BOUNCE', 'COMPLAINT', 'DELIVERY'],
                'SnsDestination' => ['TopicArn' => 'arn:aws:sns:eu-central-1:123456789012:messenger-events'],
            ];
        }

        public function upsertEventDestination(string $configurationSetName, string $eventDestinationName, string $topicArn, array $eventTypes, bool $enabled = true): void {}

        public function deleteEventDestination(string $configurationSetName, string $eventDestinationName): void {}

        public function deleteConfigurationSet(string $configurationSetName): void {}

        public function getEmailIdentity(string $identity): array
        {
            return [
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => [
                    'Tokens' => ['aaa', 'bbb', 'ccc'],
                ],
                'MailFromAttributes' => [
                    'MailFromDomainStatus' => 'SUCCESS',
                ],
            ];
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
    });

    $url = URL::temporarySignedRoute('messenger.ses-sns.dashboard', now()->addMinutes(10));

    $this->get($url)
        ->assertOk()
        ->assertSee('Amazon SES + SNS Dashboard')
        ->assertSee('Sending Checks (SES)')
        ->assertSee('Tracking Checks (SES/SNS)')
        ->assertSee('SES identity verified for sending')
        ->assertSee('SNS topic exists')
        ->assertSee('SNS topic policy allows SES publish')
        ->assertSee('SES configuration set exists');
});
