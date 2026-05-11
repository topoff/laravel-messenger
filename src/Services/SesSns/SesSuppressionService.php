<?php

namespace Topoff\Messenger\Services\SesSns;

use Aws\SesV2\SesV2Client;
use DateTimeInterface;
use Generator;
use RuntimeException;
use Throwable;

/**
 * Account-level Amazon SES suppression list management.
 *
 * SES maintains two suppression lists:
 *  - Global (account-wide, AWS-managed; not accessible via API — clearing requires AWS support)
 *  - Account-level (per-account, fully managed via SES v2 API — this class)
 *
 * For account-level entries to take precedence over global ones, enable account-level
 * suppression via PutAccountSuppressionAttributes (one-time setup).
 *
 * Required IAM permissions:
 *  - ses:GetSuppressedDestination
 *  - ses:PutSuppressedDestination
 *  - ses:DeleteSuppressedDestination
 *  - ses:ListSuppressedDestinations
 */
class SesSuppressionService
{
    protected SesV2Client $sesV2;

    public function __construct(?SesV2Client $sesV2 = null)
    {
        if (! class_exists(SesV2Client::class)) {
            throw new RuntimeException('AWS SDK classes not found. Please install aws/aws-sdk-php.');
        }

        $this->sesV2 = $sesV2 ?? new SesV2Client($this->sharedAwsConfig());
    }

    /**
     * Check whether an email is currently in the account-level suppression list.
     */
    public function isSuppressed(string $email): bool
    {
        try {
            $this->sesV2->getSuppressedDestination(['EmailAddress' => $email]);

            return true;
        } catch (Throwable $e) {
            if ($this->isNotFound($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Manually add an email to the account-level suppression list.
     *
     * @param  string  $reason  One of: BOUNCE, COMPLAINT
     */
    public function suppress(string $email, string $reason = 'BOUNCE'): void
    {
        $this->sesV2->putSuppressedDestination([
            'EmailAddress' => $email,
            'Reason' => $reason,
        ]);
    }

    /**
     * Remove an email from the account-level suppression list.
     *
     * Returns true if an entry was removed, false if the email was not on the list.
     */
    public function unsuppress(string $email): bool
    {
        try {
            $this->sesV2->deleteSuppressedDestination(['EmailAddress' => $email]);

            return true;
        } catch (Throwable $e) {
            if ($this->isNotFound($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Iterate the account-level suppression list, paging through results.
     *
     * @param  string|null  $reason  Filter by reason: BOUNCE or COMPLAINT (null = all)
     * @return Generator<int, array<string, mixed>>
     */
    public function list(
        ?DateTimeInterface $startDate = null,
        ?DateTimeInterface $endDate = null,
        ?string $reason = null,
    ): Generator {
        $baseParams = [];
        if ($startDate instanceof DateTimeInterface) {
            $baseParams['StartDate'] = (int) $startDate->format('U');
        }
        if ($endDate instanceof DateTimeInterface) {
            $baseParams['EndDate'] = (int) $endDate->format('U');
        }
        if ($reason !== null && $reason !== '') {
            $baseParams['Reasons'] = [$reason];
        }

        $nextToken = null;
        do {
            $params = $baseParams;
            if ($nextToken !== null) {
                $params['NextToken'] = $nextToken;
            }

            $result = $this->sesV2->listSuppressedDestinations($params);
            $summaries = (array) ($result['SuppressedDestinationSummaries'] ?? []);

            foreach ($summaries as $summary) {
                yield (array) $summary;
            }

            $nextToken = $result['NextToken'] ?? null;
        } while (is_string($nextToken) && $nextToken !== '');
    }

    protected function isNotFound(Throwable $e): bool
    {
        $errorCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';

        return $errorCode === 'NotFoundException';
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
