<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Topoff\Messenger\Contracts\CreatesRoute53RecordsIfMissing;
use Topoff\Messenger\Services\SesSns\InfomaniakDnsApi;
use Topoff\Messenger\Services\SesSns\SesSendingSetupService;
use Topoff\Messenger\Tests\Doubles\InMemorySesSnsProvisioningApi;

/*
 * The DNS automation of `messenger:ses-sns:setup-sending` must never overwrite
 * `_dmarc`: it only creates the record for an explicit `dmarc` policy and only
 * when nothing exists at that name yet. DKIM / MAIL FROM keep their upsert.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('messenger.ses_sns.aws.region', 'eu-central-1');
    config()->set('messenger.ses_sns.aws.dkim_domain');
    config()->set('messenger.ses_sns.tenant.name', '');
    config()->set('messenger.ses_sns.configuration_sets', [
        'default' => ['configuration_set' => 'cs', 'event_destination' => 'ed', 'identity' => 'default'],
    ]);
    config()->set('messenger.ses_sns.sending.infomaniak.enabled', false);
    config()->set('messenger.ses_sns.sending.infomaniak.auto_create_records', true);
    config()->set('messenger.ses_sns.sending.infomaniak.token', 'tok');
    config()->set('messenger.ses_sns.sending.route53.enabled', false);
    config()->set('messenger.ses_sns.sending.route53.auto_create_records', false);
    config()->set('messenger.ses_sns.sending.route53.hosted_zone_id', '');
});

/**
 * @param  array<string, array<string, mixed>>  $identities
 */
function dmarcIdentities(array $identities): void
{
    config()->set('messenger.ses_sns.sending.identities', $identities);
}

function dmarcProvisioningDouble(): InMemorySesSnsProvisioningApi
{
    return new class extends InMemorySesSnsProvisioningApi
    {
        public function getEmailIdentity(string $identity): array
        {
            return [
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => ['Tokens' => ['aaa'], 'Status' => 'SUCCESS'],
                'MailFromAttributes' => ['MailFromDomainStatus' => 'SUCCESS'],
            ];
        }
    };
}

/**
 * Route53 double that records every write and knows which names are taken.
 */
function route53DmarcDouble(array $existingNames = [], ?Throwable $createFails = null): InMemorySesSnsProvisioningApi
{
    return new class($existingNames, $createFails) extends InMemorySesSnsProvisioningApi implements CreatesRoute53RecordsIfMissing
    {
        /** @var array<int, array{zone: string, name: string, type: string, values: array<int, string>}> */
        public array $upserts = [];

        /** @var array<int, array{zone: string, name: string, type: string, values: array<int, string>}> */
        public array $creates = [];

        public function __construct(public array $existingNames, public ?Throwable $createFails) {}

        public function getEmailIdentity(string $identity): array
        {
            return [
                'VerifiedForSendingStatus' => true,
                'DkimAttributes' => ['Tokens' => ['aaa'], 'Status' => 'SUCCESS'],
                'MailFromAttributes' => ['MailFromDomainStatus' => 'SUCCESS'],
            ];
        }

        public function findHostedZoneIdByDomain(string $domain): string
        {
            return 'Z1';
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void
        {
            $this->upserts[] = ['zone' => $hostedZoneId, 'name' => $recordName, 'type' => $recordType, 'values' => $values];
        }

        public function createRoute53RecordIfMissing(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): bool
        {
            if ($this->createFails instanceof Throwable) {
                throw $this->createFails;
            }
            if (in_array($recordName, $this->existingNames, true)) {
                return false;
            }
            $this->creates[] = ['zone' => $hostedZoneId, 'name' => $recordName, 'type' => $recordType, 'values' => $values];

            return true;
        }
    };
}

/**
 * Fake one Infomaniak zone `example.com` with the given existing records.
 *
 * @param  array<int, array<string, mixed>>  $existing
 * @param  array<string, mixed>|null  $listOverride  raw body for the record list (malformed responses)
 */
function fakeInfomaniakZone(array $existing, ?array $listOverride = null): void
{
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response(
            $listOverride ?? ['result' => 'success', 'data' => $existing]
        ),
        in_array($request->method(), ['POST', 'PUT'], true) => Http::response(['result' => 'success', 'data' => ['id' => 99]]),
        $request->method() === 'DELETE' => Http::response(['result' => 'success']),
        default => Http::response(['unexpected' => $request->method().' '.$request->url()], 500),
    });
}

function dmarcWrites(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true))
        ->filter(fn (Request $r): bool => str_starts_with((string) ($r->data()['source'] ?? ''), '_dmarc') || $r->method() === 'DELETE')
        ->values()
        ->all();
}

function dmarcStep(array $result): ?array
{
    return collect($result['steps'])->first(fn (array $s): bool => str_starts_with($s['label'], 'DMARC'));
}

function infomaniakService(InMemorySesSnsProvisioningApi $api): SesSendingSetupService
{
    config()->set('messenger.ses_sns.sending.infomaniak.enabled', true);
    $service = new SesSendingSetupService($api);
    $service->setDkimEndpointResolver(fn (): bool => false);
    $service->setInfomaniakDnsApi(new InfomaniakDnsApi('tok'));

    return $service;
}

function route53Service(InMemorySesSnsProvisioningApi $api): SesSendingSetupService
{
    config()->set('messenger.ses_sns.sending.route53.enabled', true);
    config()->set('messenger.ses_sns.sending.route53.auto_create_records', true);
    $service = new SesSendingSetupService($api);
    $service->setDkimEndpointResolver(fn (): bool => false);

    return $service;
}

$hardened = ['id' => 7, 'source' => '_dmarc', 'type' => 'TXT', 'target' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.org', 'ttl' => 3600, 'description' => []];

it('infomaniak: keeps an existing hardened _dmarc although a different policy is configured', function () use ($hardened) {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;']]);
    fakeInfomaniakZone([$hardened]);

    $result = infomaniakService(dmarcProvisioningDouble())->setup();

    expect(dmarcWrites())->toBe([])
        ->and(dmarcStep($result))->toMatchArray(['ok' => true])
        ->and(dmarcStep($result)['details'])->toContain('already exists; left unchanged');

    // DKIM is still reconciled via upsert.
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && ($r->data()['source'] ?? null) === 'aaa._domainkey');
});

it('infomaniak: creates a missing _dmarc with the explicitly configured policy', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=reject; rua=mailto:r@example.org']]);
    fakeInfomaniakZone([]);

    $result = infomaniakService(dmarcProvisioningDouble())->setup();

    $writes = dmarcWrites();
    expect($writes)->toHaveCount(1)
        ->and($writes[0]->method())->toBe('POST')
        ->and($writes[0]->data())->toMatchArray(['source' => '_dmarc', 'type' => 'TXT', 'target' => 'v=DMARC1; p=reject; rua=mailto:r@example.org'])
        ->and(dmarcStep($result))->toMatchArray(['ok' => true, 'details' => 'Created _dmarc.example.com.']);
});

it('infomaniak: treats any record type at _dmarc (e.g. a CNAME) as taken', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;']]);
    fakeInfomaniakZone([['id' => 3, 'source' => '_dmarc', 'type' => 'CNAME', 'target' => 'dmarc.provider.example', 'ttl' => 300, 'description' => []]]);

    infomaniakService(dmarcProvisioningDouble())->setup();

    expect(dmarcWrites())->toBe([]);
});

it('infomaniak: writes nothing for DMARC when dmarc is not configured, even for a free subdomain name', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'mailer.example.com', 'mail_from_domain' => 'bounce.mailer.example.com']]);
    fakeInfomaniakZone([]);

    $result = infomaniakService(dmarcProvisioningDouble())->setup();

    expect(dmarcWrites())->toBe([])
        ->and(dmarcStep($result)['details'])->toContain('Not managed')
        ->and(collect($result['dns_records'])->pluck('name')->all())->toContain('_dmarc.mailer.example.com');
});

it('infomaniak: dmarc => false writes nothing and reports no DMARC record', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => false]]);
    fakeInfomaniakZone([]);

    $result = infomaniakService(dmarcProvisioningDouble())->setup();

    expect(dmarcWrites())->toBe([])
        ->and(collect($result['dns_records'])->pluck('name')->all())->not->toContain('_dmarc.example.com');
});

it('infomaniak: a malformed record listing fails the DMARC step instead of writing, DKIM still upserted', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;']]);
    fakeInfomaniakZone([], ['result' => 'error', 'error' => ['code' => 'boom']]);

    $result = infomaniakService(dmarcProvisioningDouble())->setup();

    expect(collect(dmarcWrites())->filter(fn (Request $r): bool => ($r->data()['source'] ?? '') === '_dmarc'))->toBeEmpty()
        ->and(dmarcStep($result)['ok'])->toBeFalse()
        ->and(dmarcStep($result)['details'])->toContain('did not report success');
});

it('creates a shared _dmarc only once when two identities configure the same policy', function () {
    dmarcIdentities([
        'default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=reject;'],
        'app' => ['identity_domain' => 'noreply@example.com', 'dmarc' => 'v=DMARC1; p=reject;'],
    ]);
    fakeInfomaniakZone([]);

    infomaniakService(dmarcProvisioningDouble())->setup();

    expect(dmarcWrites())->toHaveCount(1);
});

it('writes nothing for a _dmarc name with conflicting policies across identities', function () {
    dmarcIdentities([
        'default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;'],
        'app' => ['identity_domain' => 'noreply@example.com', 'dmarc' => 'v=DMARC1; p=reject;'],
    ]);
    fakeInfomaniakZone([]);

    $result = infomaniakService(dmarcProvisioningDouble())->setup();

    expect(dmarcWrites())->toBe([])
        ->and(dmarcStep($result)['ok'])->toBeFalse()
        ->and(dmarcStep($result)['details'])->toContain('conflicting');
});

it('detects conflicting policies regardless of case and trailing dot', function () {
    dmarcIdentities([
        'default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;'],
        'app' => ['identity_domain' => 'Example.COM.', 'dmarc' => 'v=DMARC1; p=reject;'],
    ]);
    $api = route53DmarcDouble();

    $result = route53Service($api)->setup();

    expect($api->creates)->toBe([])
        ->and(dmarcStep($result)['ok'])->toBeFalse()
        ->and(dmarcStep($result)['details'])->toContain('conflicting');
});

it('route53: keeps an existing _dmarc, still upserts DKIM', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;']]);
    $api = route53DmarcDouble(['_dmarc.example.com']);

    $result = route53Service($api)->setup();

    expect($api->creates)->toBe([])
        ->and(collect($api->upserts)->pluck('name')->all())->not->toContain('_dmarc.example.com')
        ->and(collect($api->upserts)->pluck('type')->all())->toContain('CNAME')
        ->and(dmarcStep($result)['details'])->toContain('already exists; left unchanged');
});

it('route53: creates a missing _dmarc exactly once', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=quarantine;']]);
    $api = route53DmarcDouble();

    route53Service($api)->setup();

    expect($api->creates)->toBe([['zone' => 'Z1', 'name' => '_dmarc.example.com', 'type' => 'TXT', 'values' => ['"v=DMARC1; p=quarantine;"']]])
        ->and(collect($api->upserts)->pluck('name')->all())->not->toContain('_dmarc.example.com');
});

it('route53: dmarc => false and missing dmarc write nothing', function (mixed $dmarc) {
    $identity = ['identity_domain' => 'example.com'];
    if ($dmarc !== 'unset') {
        $identity['dmarc'] = $dmarc;
    }
    dmarcIdentities(['default' => $identity]);
    $api = route53DmarcDouble();

    route53Service($api)->setup();

    expect($api->creates)->toBe([])
        ->and(collect($api->upserts)->pluck('name')->all())->not->toContain('_dmarc.example.com');
})->with(['false' => [false], 'unset' => ['unset'], 'empty string' => ['  ']]);

it('route53: a failing create (missing permission / CREATE conflict) fails the step but setup continues', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;']]);
    $api = route53DmarcDouble([], new RuntimeException('AccessDenied: route53:ListResourceRecordSets'));

    $result = route53Service($api)->setup();

    expect(dmarcStep($result)['ok'])->toBeFalse()
        ->and(dmarcStep($result)['details'])->toContain('AccessDenied')
        ->and(collect($api->upserts)->pluck('type')->all())->toContain('CNAME')
        ->and(collect($result['steps'])->pluck('label')->all())->toContain('SES configuration set');
});

it('route53: a provisioning API without the create-if-missing capability never writes _dmarc', function () {
    dmarcIdentities(['default' => ['identity_domain' => 'example.com', 'dmarc' => 'v=DMARC1; p=none;']]);
    $api = new class extends InMemorySesSnsProvisioningApi
    {
        /** @var array<int, string> */
        public array $upserted = [];

        public function getEmailIdentity(string $identity): array
        {
            return ['VerifiedForSendingStatus' => true, 'DkimAttributes' => ['Tokens' => ['aaa']]];
        }

        public function findHostedZoneIdByDomain(string $domain): string
        {
            return 'Z1';
        }

        public function upsertRoute53Record(string $hostedZoneId, string $recordName, string $recordType, array $values, int $ttl = 300): void
        {
            $this->upserted[] = $recordName;
        }
    };

    $result = route53Service($api)->setup();

    expect($api->upserted)->not->toContain('_dmarc.example.com')
        ->and(dmarcStep($result)['ok'])->toBeFalse()
        ->and(dmarcStep($result)['details'])->toContain('cannot check for an existing');
});
