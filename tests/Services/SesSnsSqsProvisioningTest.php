<?php

use Topoff\Messenger\Services\SesSns\SesSnsSetupService;
use Topoff\Messenger\Tests\Doubles\InMemorySesSnsProvisioningApi;

beforeEach(function () {
    config()->set('messenger.tracking.event_transport', 'sqs');
    config()->set('messenger.ses_sns.aws.region', 'eu-central-1');
    config()->set('messenger.ses_sns.aws.account_id', '123456789012');
    config()->set('messenger.ses_sns.topic_name', 'messenger-events');
    config()->set('messenger.ses_sns.sqs.queue_name', 'messenger-events-queue');
    config()->set('messenger.ses_sns.sqs.dlq_name', 'messenger-events-dlq');
    config()->set('messenger.ses_sns.sqs.raw_message_delivery', false);
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => [
            'configuration_set' => 'messenger-tracking',
            'event_destination' => 'messenger-sns',
            'identity' => 'default',
        ],
    ]);
    config()->set('messenger.ses_sns.tenant.name');
});

it('provisions queue, dlq, policy and subscription for the sqs transport', function () {
    $fake = new InMemorySesSnsProvisioningApi;
    $service = new SesSnsSetupService($fake);

    $result = $service->setup();

    expect($result['ok'])->toBeTrue()
        ->and($fake->queues)->toHaveKeys(['messenger-events-queue', 'messenger-events-dlq'])
        ->and($fake->sqsSubscriptions)->toHaveCount(1);

    $queueUrl = $fake->queues['messenger-events-queue'];
    $attributes = $fake->queueAttributes[$queueUrl];

    expect($attributes)->toHaveKey('Policy')
        ->and($attributes)->toHaveKey('RedrivePolicy');

    $redrive = json_decode($attributes['RedrivePolicy'], true);
    expect($redrive['deadLetterTargetArn'])->toContain('messenger-events-dlq');

    $policy = json_decode($attributes['Policy'], true);
    expect($policy['Statement'][0]['Principal']['Service'])->toBe('sns.amazonaws.com')
        ->and($policy['Statement'][0]['Action'])->toBe('sqs:SendMessage');
});

it('reports green checks for a fully provisioned sqs transport', function () {
    $fake = new InMemorySesSnsProvisioningApi;
    $service = new SesSnsSetupService($fake);
    $service->setup();

    $status = $service->check();

    expect($status['ok'])->toBeTrue()
        ->and($status['configuration']['event_transport'])->toBe('sqs')
        ->and(collect($status['checks'])->firstWhere('key', 'sqs_queue')['ok'])->toBeTrue()
        ->and(collect($status['checks'])->firstWhere('key', 'sns_subscription')['ok'])->toBeTrue()
        ->and(collect($status['checks'])->firstWhere('key', 'sqs_dlq')['ok'])->toBeTrue();
});

it('subscribes with raw message delivery when configured', function () {
    config()->set('messenger.ses_sns.sqs.raw_message_delivery', true);

    $fake = new InMemorySesSnsProvisioningApi;
    new SesSnsSetupService($fake)->setup();

    expect($fake->sqsSubscriptions[0]['raw'])->toBeTrue();
});

it('tears down the sqs queue, dlq and subscription', function () {
    $fake = new InMemorySesSnsProvisioningApi;
    $service = new SesSnsSetupService($fake);
    $service->setup();

    expect($fake->queues)->not->toBeEmpty();

    $result = $service->teardown();

    expect($result['ok'])->toBeTrue()
        ->and($fake->queues)->toBeEmpty()
        ->and($fake->sqsSubscriptions)->toBeEmpty()
        ->and($fake->topicArn)->toBeNull();
});
