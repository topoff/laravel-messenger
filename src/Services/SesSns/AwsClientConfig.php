<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\SesSns;

class AwsClientConfig
{
    /**
     * Build the shared AWS SDK client configuration from messenger.ses_sns.aws.*.
     *
     * @return array<string, mixed>
     */
    public static function shared(): array
    {
        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
        $profile = config('messenger.ses_sns.aws.profile');
        $key = config('messenger.ses_sns.aws.key');
        $secret = config('messenger.ses_sns.aws.secret');
        $sessionToken = config('messenger.ses_sns.aws.session_token');

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
