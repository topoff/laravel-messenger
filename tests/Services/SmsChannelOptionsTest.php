<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Topoff\Messenger\Notifications\NovaChannelNotification;
use Topoff\Messenger\Services\Sms\SmsChannelOptions;

function configureAwsSms(): void
{
    config()->set('messenger.sms.aws.key', 'AKIATEST');
    config()->set('messenger.sms.aws.secret', 'secret');
}

it('offers only vonage when aws sms credentials are missing', function (): void {
    config()->set('messenger.sms.aws.key');
    config()->set('messenger.sms.aws.secret');

    expect(SmsChannelOptions::channels())->toBe(['vonage' => 'SMS (Vonage)'])
        ->and(SmsChannelOptions::isSms('aws-sms'))->toBeFalse()
        ->and(SmsChannelOptions::isSms('mail'))->toBeFalse();
});

it('offers aws and vonage when aws sms credentials are set', function (): void {
    configureAwsSms();

    expect(SmsChannelOptions::channels())->toBe(['aws-sms' => 'SMS (AWS)', 'vonage' => 'SMS (Vonage)'])
        ->and(SmsChannelOptions::isSms('aws-sms'))->toBeTrue()
        ->and(SmsChannelOptions::isSms('vonage'))->toBeTrue();
});

it('preselects the configured sms channel when it is offered', function (): void {
    configureAwsSms();
    config()->set('messenger.notifications.default_sms_channel', 'aws-sms');

    expect(SmsChannelOptions::defaultChannel())->toBe('aws-sms');
});

it('falls back to vonage when the configured default is not offered', function (): void {
    config()->set('messenger.sms.aws.key');
    config()->set('messenger.notifications.default_sms_channel', 'aws-sms');

    expect(SmsChannelOptions::defaultChannel())->toBe('vonage');

    config()->set('messenger.notifications.default_sms_channel', 'unknown');

    expect(SmsChannelOptions::defaultChannel())->toBe('vonage');
});

it('returns the configured price hint per channel', function (): void {
    config()->set('messenger.notifications.sms_price_hints', ['aws-sms' => 'USD 0.05', 'vonage' => '']);

    expect(SmsChannelOptions::priceHint('aws-sms'))->toBe('USD 0.05')
        ->and(SmsChannelOptions::priceHint('vonage'))->toBeNull()
        ->and(SmsChannelOptions::priceHint('mail'))->toBeNull();
});

it('sends the nova channel notification text through the aws sms channel', function (): void {
    $notification = new NovaChannelNotification('', 'Hallo AWS', 'aws-sms');

    expect($notification->via(new AnonymousNotifiable))->toBe(['aws-sms'])
        ->and($notification->toAwsSms(new AnonymousNotifiable))->toBe('Hallo AWS');
});
