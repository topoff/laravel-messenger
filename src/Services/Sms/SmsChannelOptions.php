<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Sms;

/**
 * SMS providers offered by the manual send actions (Nova, Filament). Vonage is
 * always offered; AWS (`aws-sms`) only when its credentials are configured.
 * The host preselects its active provider via
 * `messenger.notifications.default_sms_channel` and may show a price hint per
 * channel via `messenger.notifications.sms_price_hints`.
 */
final class SmsChannelOptions
{
    public const string VONAGE = 'vonage';

    public const string AWS = 'aws-sms';

    /**
     * @return array<string, string> channel => label
     */
    public static function channels(): array
    {
        $channels = [self::VONAGE => 'SMS (Vonage)'];

        if (self::awsConfigured()) {
            return [self::AWS => 'SMS (AWS)'] + $channels;
        }

        return $channels;
    }

    public static function isSms(string $channel): bool
    {
        return array_key_exists($channel, self::channels());
    }

    /**
     * The configured default when it is offered, otherwise Vonage.
     */
    public static function defaultChannel(): string
    {
        $configured = (string) config('messenger.notifications.default_sms_channel', self::VONAGE);

        return self::isSms($configured) ? $configured : self::VONAGE;
    }

    public static function priceHint(string $channel): ?string
    {
        $hint = config('messenger.notifications.sms_price_hints.'.$channel);

        return is_string($hint) && $hint !== '' ? $hint : null;
    }

    private static function awsConfigured(): bool
    {
        return (string) config('messenger.sms.aws.key') !== ''
            && (string) config('messenger.sms.aws.secret') !== '';
    }
}
