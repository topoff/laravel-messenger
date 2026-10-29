<?php

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Aws\Result;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Topoff\Messenger\Channels\AwsSmsChannel;
use Topoff\Messenger\Channels\AwsSmsMessage;
use Topoff\Messenger\Channels\AwsSmsSentMessage;

beforeEach(function () {
    config()->set('messenger.sms.aws', [
        'key' => 'k',
        'secret' => 's',
        'region' => 'eu-central-2',
        'origination_identity' => 'TOPOFFERTEN',
        'configuration_set' => 'top-offerten-sms',
        'message_type' => 'TRANSACTIONAL',
    ]);
});

/**
 * @return array{0: AwsSmsChannel, 1: ArrayObject<int, CommandInterface>}
 */
function awsSmsChannelWithMock(string $messageId = 'aws-msg-1'): array
{
    $commands = new ArrayObject;
    $mock = new MockHandler;
    $mock->append(function (CommandInterface $command) use ($commands, $messageId): Result {
        $commands->append($command);

        return new Result(['MessageId' => $messageId]);
    });

    $client = new PinpointSMSVoiceV2Client([
        'version' => 'latest',
        'region' => 'eu-central-2',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $mock,
    ]);

    return [new AwsSmsChannel($client), $commands];
}

function awsSmsNotification(string|AwsSmsMessage $content = 'Hallo', ?int $messengerMessageId = null): Notification
{
    $notification = new class($content) extends Notification
    {
        public function __construct(private readonly string|AwsSmsMessage $content) {}

        public function via(object $notifiable): array
        {
            return ['aws-sms'];
        }

        public function toAwsSms(object $notifiable): string|AwsSmsMessage
        {
            return $this->content;
        }
    };
    $notification->messengerMessageId = $messengerMessageId;

    return $notification;
}

it('sends with sender id, configuration set, message type and messenger context', function () {
    [$channel, $commands] = awsSmsChannelWithMock();
    $notifiable = new AnonymousNotifiable()->route('aws-sms', '+41791234567');

    $response = $channel->send($notifiable, awsSmsNotification('Hallo', 42));

    expect($response)->toBeInstanceOf(AwsSmsSentMessage::class)
        ->and($response->getMessageId())->toBe('aws-msg-1')
        ->and($response->getTo())->toBe('+41791234567')
        ->and(iterator_to_array($response))->toHaveCount(1);

    $command = $commands[0];
    expect($command->getName())->toBe('SendTextMessage')
        ->and($command['DestinationPhoneNumber'])->toBe('+41791234567')
        ->and($command['OriginationIdentity'])->toBe('TOPOFFERTEN')
        ->and($command['MessageType'])->toBe('TRANSACTIONAL')
        ->and($command['ConfigurationSetName'])->toBe('top-offerten-sms')
        ->and($command['MessageBody'])->toBe('Hallo')
        ->and($command['Context'])->toBe(['messenger_message_id' => '42']);
});

it('accepts an AwsSmsMessage and omits the context without messenger id', function () {
    [$channel, $commands] = awsSmsChannelWithMock();
    $notifiable = new AnonymousNotifiable()->route('aws-sms', '+41791234567');

    $channel->send($notifiable, awsSmsNotification(new AwsSmsMessage('Objekt-Text')));

    expect($commands[0]['MessageBody'])->toBe('Objekt-Text')
        ->and($commands[0]['Context'] ?? null)->toBeNull();
});

it('sends nothing without a route', function () {
    [$channel, $commands] = awsSmsChannelWithMock();

    expect($channel->send(new AnonymousNotifiable, awsSmsNotification()))->toBeNull()
        ->and($commands)->toHaveCount(0);
});

it('refuses to build a client without dedicated credentials', function () {
    config()->set('messenger.sms.aws.secret');
    $notifiable = new AnonymousNotifiable()->route('aws-sms', '+41791234567');

    new AwsSmsChannel()->send($notifiable, awsSmsNotification());
})->throws(RuntimeException::class, 'AWS SMS credentials are not configured');

it('does not retry a send inside the sdk (no idempotency token)', function () {
    $channel = new AwsSmsChannel;
    $client = new ReflectionMethod(AwsSmsChannel::class, 'client')->invoke($channel);

    $mock = new MockHandler;
    $mock->append(
        fn (CommandInterface $command) => new AwsException('connection reset', $command, ['connection_error' => true]),
        new Result(['MessageId' => 'second-attempt']),
    );
    $client->getHandlerList()->setHandler($mock);
    $notifiable = new AnonymousNotifiable()->route('aws-sms', '+41791234567');

    expect(fn () => $channel->send($notifiable, awsSmsNotification()))->toThrow(AwsException::class)
        ->and($mock->count())->toBe(1);
});

it('is registered as notification channel aws-sms', function () {
    expect(app(ChannelManager::class)->driver('aws-sms'))->toBeInstanceOf(AwsSmsChannel::class);
});
