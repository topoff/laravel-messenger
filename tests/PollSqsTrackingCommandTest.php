<?php

use Topoff\Messenger\Services\SesSns\SqsTrackingPoller;

it('drains the queue and reports the processed count', function () {
    config()->set('messenger.tracking.event_transport', 'sqs');

    $poller = Mockery::mock(SqsTrackingPoller::class);
    $poller->shouldReceive('poll')->once()->andReturn(3);
    app()->instance(SqsTrackingPoller::class, $poller);

    $this->artisan('messenger:tracking:sqs-poll', ['--once' => true])
        ->expectsOutputToContain('Processed 3 SES tracking message(s) from SQS.')
        ->assertSuccessful();
});

it('warns when the configured transport is not sqs', function () {
    config()->set('messenger.tracking.event_transport', 'sns_http');

    $poller = Mockery::mock(SqsTrackingPoller::class);
    $poller->shouldReceive('poll')->once()->andReturn(0);
    app()->instance(SqsTrackingPoller::class, $poller);

    $this->artisan('messenger:tracking:sqs-poll', ['--once' => true])
        ->expectsOutputToContain('event_transport is not')
        ->assertSuccessful();
});
