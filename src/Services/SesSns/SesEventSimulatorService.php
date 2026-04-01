<?php

namespace Topoff\Messenger\Services\SesSns;

use Aws\SesV2\SesV2Client;

class SesEventSimulatorService
{
    protected object $sesV2;

    public function __construct()
    {
        $sesClientClass = SesV2Client::class;
        if (! class_exists($sesClientClass)) {
            throw new \RuntimeException('AWS SDK classes not found. Please install aws/aws-sdk-php.');
        }

        $this->sesV2 = new $sesClientClass($this->sharedAwsConfig());
    }

    /**
     * @param  array<int, array{Name: string, Value: string}>  $tags
     */
    public function send(
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $configurationSetName = null,
        ?string $tenantName = null,
        array $tags = [],
    ): string {
        $payload = [
            'FromEmailAddress' => $fromEmail,
            'Destination' => ['ToAddresses' => [$toEmail]],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $subject],
                    'Body' => [
                        'Text' => ['Data' => $textBody],
                    ],
                ],
            ],
        ];

        if ($configurationSetName !== null && $configurationSetName !== '') {
            $payload['ConfigurationSetName'] = $configurationSetName;
        }

        if ($tenantName !== null && $tenantName !== '') {
            $payload['TenantName'] = $tenantName;
        }

        if ($tags !== []) {
            $payload['EmailTags'] = array_values($tags);
        }

        $result = $this->sesV2->sendEmail($payload);

        return (string) ($result['MessageId'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedAwsConfig(): array
    {
        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
        $profile = config('messenger.ses_sns.aws.profile');
        $key = config('messenger.ses_sns.aws.key');
        $secret = config('messenger.ses_sns.aws.secret');
        $sessionToken = config('messenger.ses_sns.aws.session_token');

        $config = [
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'eu-central-1',
        ];

        if (is_string($profile) && $profile !== '') {
            $config['profile'] = $profile;
        } elseif (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
            $credentials = [
                'key' => $key,
                'secret' => $secret,
            ];
            if (is_string($sessionToken) && $sessionToken !== '') {
                $credentials['token'] = $sessionToken;
            }
            $config['credentials'] = $credentials;
        }

        return $config;
    }
}
