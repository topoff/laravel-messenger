<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Route53\Route53Client;
use Topoff\Messenger\Services\SesSns\AwsSesSnsProvisioningApi;

/**
 * @param  array<int, array<string, mixed>>  $existingRecordSets  what ListResourceRecordSets returns
 * @return array{api: AwsSesSnsProvisioningApi, commands: ArrayObject<int, CommandInterface>}
 */
function route53ApiWith(array $existingRecordSets): array
{
    $commands = new ArrayObject;
    $mock = new MockHandler;
    $mock->append(function (CommandInterface $cmd) use ($commands, $existingRecordSets): Result {
        $commands[] = $cmd;

        return new Result(['ResourceRecordSets' => $existingRecordSets, 'IsTruncated' => false, 'MaxItems' => '1']);
    });
    $mock->append(function (CommandInterface $cmd) use ($commands): Result {
        $commands[] = $cmd;

        return new Result(['ChangeInfo' => ['Id' => 'c1', 'Status' => 'PENDING', 'SubmittedAt' => '2026-11-12T00:00:00Z']]);
    });

    $client = new Route53Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $mock,
    ]);

    $api = new ReflectionClass(AwsSesSnsProvisioningApi::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(AwsSesSnsProvisioningApi::class, 'route53')->setValue($api, $client);

    return ['api' => $api, 'commands' => $commands];
}

it('does not create when a record set of any type exists at the name', function (array $recordSet) {
    ['api' => $api, 'commands' => $commands] = route53ApiWith([$recordSet]);

    $created = $api->createRoute53RecordIfMissing('Z1', '_dmarc.example.com', 'TXT', ['"v=DMARC1; p=none;"']);

    expect($created)->toBeFalse()
        ->and($commands)->toHaveCount(1)
        ->and($commands[0]->getName())->toBe('ListResourceRecordSets')
        ->and($commands[0]->toArray())->toMatchArray(['HostedZoneId' => 'Z1', 'StartRecordName' => '_dmarc.example.com', 'MaxItems' => '1'])
        ->and($commands[0]->toArray())->not->toHaveKey('StartRecordType');
})->with([
    'TXT, trailing dot' => [['Name' => '_dmarc.example.com.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"v=DMARC1; p=reject;"']]]],
    'CNAME' => [['Name' => '_dmarc.example.com.', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => 'x.example.org']]]],
    'upper case' => [['Name' => '_DMARC.Example.COM.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"v=DMARC1; p=reject;"']]]],
]);

it('creates with Action CREATE when the name is free', function (array $existing) {
    ['api' => $api, 'commands' => $commands] = route53ApiWith($existing);

    $created = $api->createRoute53RecordIfMissing('Z1', '_dmarc.example.com', 'txt', ['"v=DMARC1; p=quarantine;"'], 3600);

    expect($created)->toBeTrue()
        ->and($commands)->toHaveCount(2)
        ->and($commands[1]->getName())->toBe('ChangeResourceRecordSets')
        ->and($commands[1]->toArray()['ChangeBatch']['Changes'])->toBe([[
            'Action' => 'CREATE',
            'ResourceRecordSet' => [
                'Name' => '_dmarc.example.com',
                'Type' => 'TXT',
                'TTL' => 3600,
                'ResourceRecords' => [['Value' => '"v=DMARC1; p=quarantine;"']],
            ],
        ]]);
})->with([
    'empty zone tail' => [[]],
    'next name only' => [[['Name' => 'abc._domainkey.example.com.', 'Type' => 'CNAME', 'TTL' => 300, 'ResourceRecords' => [['Value' => 'x']]]]],
]);

it('decodes Route53 octal escapes when comparing names', function () {
    ['api' => $api] = route53ApiWith([['Name' => '\\052.example.com.', 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"x"']]]]);

    expect($api->createRoute53RecordIfMissing('Z1', '*.example.com', 'TXT', ['"x"']))->toBeFalse();
});
