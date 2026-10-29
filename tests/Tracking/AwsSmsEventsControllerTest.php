<?php

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message as SnsMessage;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Topoff\Messenger\Exceptions\SnsCertificateUnavailableException;
use Topoff\Messenger\Http\Controllers\AwsSmsEventsController;
use Topoff\Messenger\Jobs\RecordAwsSmsEventJob;

const SMS_TOPIC = 'arn:aws:sns:eu-central-2:123456789012:sms-events';

beforeEach(function () {
    config()->set('messenger.tracking.aws_sms_events', ['enabled' => true, 'topic_arn' => SMS_TOPIC]);
    acceptSnsSignatures(true);
});

function acceptSnsSignatures(bool $valid): void
{
    app()->instance(MessageValidator::class, new class($valid) extends MessageValidator
    {
        public function __construct(private readonly bool $valid) {}

        public function validate(SnsMessage $message): void
        {
            if (! $this->valid) {
                throw new RuntimeException('bad signature');
            }
        }
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function snsEnvelope(array $overrides = []): array
{
    return array_merge([
        'Type' => 'Notification',
        'MessageId' => 'sns-1',
        'TopicArn' => SMS_TOPIC,
        'Message' => json_encode(['eventType' => 'TEXT_DELIVERED', 'isFinal' => true, 'messageId' => 'aws-1']),
        'Timestamp' => '2026-10-29T10:00:00.000Z',
        'SignatureVersion' => '1',
        'Signature' => 'sig',
        'SigningCertURL' => 'https://sns.eu-central-2.amazonaws.com/cert.pem',
    ], $overrides);
}

function postSnsEnvelope(array $envelope): TestResponse
{
    return test()->call('POST', route('messenger.tracking.sms-sns'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($envelope));
}

it('is not found while disabled or without topic', function (array $config) {
    config()->set('messenger.tracking.aws_sms_events', $config);

    postSnsEnvelope(snsEnvelope())->assertNotFound();
})->with([
    'disabled' => [['enabled' => false, 'topic_arn' => SMS_TOPIC]],
    'no topic' => [['enabled' => true, 'topic_arn' => null]],
]);

it('dispatches the decoded sms event', function () {
    Bus::fake();

    postSnsEnvelope(snsEnvelope())->assertOk()->assertSee('ok');

    Bus::assertDispatched(RecordAwsSmsEventJob::class, fn (RecordAwsSmsEventJob $job): bool => $job->event['messageId'] === 'aws-1');
});

it('rejects an invalid signature', function () {
    Bus::fake();
    acceptSnsSignatures(false);

    postSnsEnvelope(snsEnvelope())->assertForbidden();

    Bus::assertNothingDispatched();
});

it('rejects a foreign topic', function () {
    Bus::fake();

    postSnsEnvelope(snsEnvelope(['TopicArn' => 'arn:aws:sns:eu-central-2:123456789012:other']))->assertForbidden();

    Bus::assertNothingDispatched();
});

it('fails closed when the signature validator is not installed', function () {
    $this->app->bind(AwsSmsEventsController::class, fn () => new class extends AwsSmsEventsController
    {
        protected function validatorInstalled(): bool
        {
            return false;
        }
    });

    postSnsEnvelope(snsEnvelope())->assertStatus(503);
});

it('confirms the subscription for the configured topic', function () {
    Http::fake(['sns.eu-central-2.amazonaws.com/*' => Http::response('<ConfirmSubscriptionResponse/>')]);

    postSnsEnvelope(snsEnvelope([
        'Type' => 'SubscriptionConfirmation',
        'Token' => 't',
        'SubscribeURL' => 'https://sns.eu-central-2.amazonaws.com/?Action=ConfirmSubscription&Token=t',
    ]))->assertOk()->assertSee('subscription confirmed');

    Http::assertSentCount(1);
});

it('reports a failed subscription confirmation so sns retries', function () {
    Http::fake(['sns.eu-central-2.amazonaws.com/*' => Http::response('nope', 500)]);

    postSnsEnvelope(snsEnvelope([
        'Type' => 'SubscriptionConfirmation',
        'Token' => 't',
        'SubscribeURL' => 'https://sns.eu-central-2.amazonaws.com/?Action=ConfirmSubscription&Token=t',
    ]))->assertStatus(502);
});

it('does not confirm a subscription of a foreign topic', function () {
    Http::fake();

    postSnsEnvelope(snsEnvelope([
        'Type' => 'SubscriptionConfirmation',
        'TopicArn' => 'arn:aws:sns:eu-central-2:123456789012:other',
        'Token' => 't',
        'SubscribeURL' => 'https://sns.eu-central-2.amazonaws.com/?Action=ConfirmSubscription&Token=t',
    ]))->assertForbidden();

    Http::assertNothingSent();
});

it('answers 503 when the signing certificate is temporarily unavailable, so sns retries', function () {
    Bus::fake();
    app()->instance(MessageValidator::class, new class extends MessageValidator
    {
        public function validate(SnsMessage $message): void
        {
            throw new SnsCertificateUnavailableException('timeout');
        }
    });

    postSnsEnvelope(snsEnvelope())->assertStatus(503);

    Bus::assertNothingDispatched();
});

it('fetches the signing certificate directly when the cache store fails', function () {
    app()->forgetInstance(MessageValidator::class);
    app()->offsetUnset(MessageValidator::class);
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('redis down'));
    Http::fake(['sns.eu-central-2.amazonaws.com/*' => Http::response('not-a-certificate')]);

    $validator = new ReflectionMethod(AwsSmsEventsController::class, 'validator')->invoke(new AwsSmsEventsController);

    // The fake certificate is no valid key: the message is rejected as invalid (403 path),
    // but the certificate was fetched despite the failing cache instead of failing as unavailable.
    expect(fn () => $validator->validate(new SnsMessage(snsEnvelope())))
        ->toThrow(InvalidSnsMessageException::class);
    Http::assertSentCount(1);
});

it('reports an unreachable certificate host as unavailable', function () {
    app()->forgetInstance(MessageValidator::class);
    app()->offsetUnset(MessageValidator::class);
    Http::fake(['sns.eu-central-2.amazonaws.com/*' => Http::response('down', 503)]);

    $validator = new ReflectionMethod(AwsSmsEventsController::class, 'validator')->invoke(new AwsSmsEventsController);

    expect(fn () => $validator->validate(new SnsMessage(snsEnvelope())))
        ->toThrow(SnsCertificateUnavailableException::class);
});
