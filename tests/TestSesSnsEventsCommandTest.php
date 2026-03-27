<?php

use Topoff\Messenger\Services\SesSns\SesEventSimulatorService;

it('sends simulator scenarios and creates message records', function () {
    config()->set('mail.from.address', 'sender@example.com');
    config()->set('messenger.ses_sns.sending.identities.default.mail_from_address', null);
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
        ],
    ]);
    config()->set('messenger.ses_sns.tenant.name', 'tenant-a');

    $fake = new class extends SesEventSimulatorService
    {
        public int $counter = 0;

        /** @var array<int, array{from:string,to:string,subject:string,configuration_set:?string,tenant:?string,tags:array}> */
        public array $calls = [];

        public function __construct() {}

        public function send(
            string $fromEmail,
            string $toEmail,
            string $subject,
            string $textBody,
            ?string $configurationSetName = null,
            ?string $tenantName = null,
            array $tags = [],
        ): string {
            $this->counter++;
            $this->calls[] = [
                'from' => $fromEmail,
                'to' => $toEmail,
                'subject' => $subject,
                'configuration_set' => $configurationSetName,
                'tenant' => $tenantName,
                'tags' => $tags,
            ];

            return 'sim-message-'.$this->counter;
        }
    };

    app()->instance(SesEventSimulatorService::class, $fake);

    $this->artisan('messenger:ses-sns:test-events', [
        '--scenario' => ['delivery', 'bounce', 'complaint'],
        '--create-message-record' => true,
        '--wait' => 0,
    ])->assertSuccessful();

    expect($fake->calls)->toHaveCount(3)
        ->and($fake->calls[0]['to'])->toBe('success@simulator.amazonses.com')
        ->and($fake->calls[1]['to'])->toBe('bounce@simulator.amazonses.com')
        ->and($fake->calls[2]['to'])->toBe('complaint@simulator.amazonses.com')
        ->and($fake->calls[0]['from'])->toBe('sender@example.com')
        ->and($fake->calls[0]['configuration_set'])->toBe('messenger-tracking')
        ->and($fake->calls[0]['tenant'])->toBe('tenant-a')
        ->and($fake->calls[0]['tags'])->toContain(['Name' => 'messenger_test', 'Value' => 'true'])
        ->and($fake->calls[0]['tags'])->toContain(['Name' => 'scenario', 'Value' => 'delivery']);

    $messageClass = config('messenger.models.message');
    expect($messageClass::query()->whereNotNull('tracking_message_id')->count())->toBe(3)
        ->and($messageClass::query()->where('tracking_message_id', 'sim-message-1')->exists())->toBeTrue()
        ->and($messageClass::query()->where('tracking_message_id', 'sim-message-2')->exists())->toBeTrue()
        ->and($messageClass::query()->where('tracking_message_id', 'sim-message-3')->exists())->toBeTrue();
});
