<?php

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Topoff\Messenger\Services\SesSns\SesSuppressionService;

/**
 * @param  array<int, Result|AwsException>  $queue
 * @return array{client: SesV2Client, calls: array<int, array{name: string, args: array<string, mixed>}>}
 */
function makeMockedSesClient(array $queue): array
{
    $calls = [];
    $mock = new MockHandler;
    foreach ($queue as $item) {
        $mock->append($item);
    }

    $client = new SesV2Client([
        'version' => 'latest',
        'region' => 'eu-central-1',
        'credentials' => ['key' => 'fake', 'secret' => 'fake'],
        'handler' => function (CommandInterface $command, $request) use ($mock, &$calls) {
            $calls[] = [
                'name' => $command->getName(),
                'args' => $command->toArray(),
            ];

            return $mock($command, $request);
        },
    ]);

    return ['client' => $client, 'calls' => &$calls];
}

function makeNotFoundException(SesV2Client $client): AwsException
{
    $command = $client->getCommand('GetSuppressedDestination', ['EmailAddress' => 'x@x']);

    return new AwsException('Not found', $command, ['code' => 'NotFoundException']);
}

it('isSuppressed returns true when SES has the address', function () {
    $ctx = makeMockedSesClient([new Result(['SuppressedDestination' => ['EmailAddress' => 'a@b.test']])]);

    $service = new SesSuppressionService($ctx['client']);

    expect($service->isSuppressed('a@b.test'))->toBeTrue();
    expect($ctx['calls'][0]['name'])->toBe('GetSuppressedDestination');
    expect($ctx['calls'][0]['args']['EmailAddress'])->toBe('a@b.test');
});

it('isSuppressed returns false on NotFoundException', function () {
    $tempCtx = makeMockedSesClient([]);
    $error = makeNotFoundException($tempCtx['client']);

    $ctx = makeMockedSesClient([$error]);
    $service = new SesSuppressionService($ctx['client']);

    expect($service->isSuppressed('missing@example.test'))->toBeFalse();
});

it('isSuppressed rethrows on non-NotFound errors', function () {
    $tempCtx = makeMockedSesClient([]);
    $cmd = $tempCtx['client']->getCommand('GetSuppressedDestination', ['EmailAddress' => 'x@x']);
    $error = new AwsException('Access denied', $cmd, ['code' => 'AccessDeniedException']);

    $ctx = makeMockedSesClient([$error]);
    $service = new SesSuppressionService($ctx['client']);

    expect(fn () => $service->isSuppressed('x@x'))->toThrow(AwsException::class);
});

it('suppress sends PutSuppressedDestination with reason', function () {
    $ctx = makeMockedSesClient([new Result([])]);

    $service = new SesSuppressionService($ctx['client']);
    $service->suppress('bad@example.test', 'COMPLAINT');

    expect($ctx['calls'][0]['name'])->toBe('PutSuppressedDestination');
    expect($ctx['calls'][0]['args']['EmailAddress'])->toBe('bad@example.test');
    expect($ctx['calls'][0]['args']['Reason'])->toBe('COMPLAINT');
});

it('suppress defaults reason to BOUNCE', function () {
    $ctx = makeMockedSesClient([new Result([])]);

    $service = new SesSuppressionService($ctx['client']);
    $service->suppress('bad@example.test');

    expect($ctx['calls'][0]['args']['Reason'])->toBe('BOUNCE');
});

it('unsuppress returns true on success', function () {
    $ctx = makeMockedSesClient([new Result([])]);

    $service = new SesSuppressionService($ctx['client']);

    expect($service->unsuppress('cleared@example.test'))->toBeTrue();
    expect($ctx['calls'][0]['name'])->toBe('DeleteSuppressedDestination');
    expect($ctx['calls'][0]['args']['EmailAddress'])->toBe('cleared@example.test');
});

it('unsuppress returns false when email was not on the list', function () {
    $tempCtx = makeMockedSesClient([]);
    $cmd = $tempCtx['client']->getCommand('DeleteSuppressedDestination', ['EmailAddress' => 'x@x']);
    $error = new AwsException('Not found', $cmd, ['code' => 'NotFoundException']);

    $ctx = makeMockedSesClient([$error]);
    $service = new SesSuppressionService($ctx['client']);

    expect($service->unsuppress('never-suppressed@example.test'))->toBeFalse();
});

it('unsuppress rethrows non-NotFound errors', function () {
    $tempCtx = makeMockedSesClient([]);
    $cmd = $tempCtx['client']->getCommand('DeleteSuppressedDestination', ['EmailAddress' => 'x@x']);
    $error = new AwsException('Access denied', $cmd, ['code' => 'AccessDeniedException']);

    $ctx = makeMockedSesClient([$error]);
    $service = new SesSuppressionService($ctx['client']);

    expect(fn () => $service->unsuppress('x@x'))->toThrow(AwsException::class);
});

it('list yields summaries across pages', function () {
    $page1 = new Result([
        'SuppressedDestinationSummaries' => [
            ['EmailAddress' => 'a@example.test', 'Reason' => 'BOUNCE'],
            ['EmailAddress' => 'b@example.test', 'Reason' => 'COMPLAINT'],
        ],
        'NextToken' => 'token-2',
    ]);
    $page2 = new Result([
        'SuppressedDestinationSummaries' => [
            ['EmailAddress' => 'c@example.test', 'Reason' => 'BOUNCE'],
        ],
    ]);

    $ctx = makeMockedSesClient([$page1, $page2]);

    $service = new SesSuppressionService($ctx['client']);
    $items = iterator_to_array($service->list(), false);

    expect($items)->toHaveCount(3);
    expect($items[0]['EmailAddress'])->toBe('a@example.test');
    expect($items[2]['EmailAddress'])->toBe('c@example.test');
    expect($ctx['calls'][1]['args']['NextToken'])->toBe('token-2');
});

it('list passes date and reason filters to SES', function () {
    $ctx = makeMockedSesClient([new Result(['SuppressedDestinationSummaries' => []])]);

    $service = new SesSuppressionService($ctx['client']);
    iterator_to_array(
        $service->list(
            startDate: new DateTimeImmutable('@1700000000'),
            endDate: new DateTimeImmutable('@1710000000'),
            reason: 'BOUNCE',
        ),
        false,
    );

    expect($ctx['calls'][0]['name'])->toBe('ListSuppressedDestinations');
    expect($ctx['calls'][0]['args']['StartDate'])->toBe(1700000000);
    expect($ctx['calls'][0]['args']['EndDate'])->toBe(1710000000);
    expect($ctx['calls'][0]['args']['Reasons'])->toBe(['BOUNCE']);
});
