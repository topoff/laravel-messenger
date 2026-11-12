<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Topoff\Messenger\Services\SesSns\InfomaniakDnsApi;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('picks the longest matching zone returned by the API', function () {
    Http::fake([
        'api.infomaniak.com/2/domains/abc._domainkey.mailer.example.com/zones' => Http::response([
            'result' => 'success',
            'data' => [
                ['fqdn' => 'example.com'],
                ['fqdn' => 'mailer.example.com'],
                ['fqdn' => 'unrelated.net'],
            ],
        ]),
    ]);

    $api = new InfomaniakDnsApi('tok');

    expect($api->resolveZone('abc._domainkey.mailer.example.com'))->toBe('mailer.example.com');
});

it('returns null when the API has no zone for the domain', function () {
    Http::fake([
        'api.infomaniak.com/2/domains/example.org/zones' => Http::response('', 404),
    ]);

    $api = new InfomaniakDnsApi('tok');

    expect($api->resolveZone('example.org'))->toBeNull();
});

it('creates a DKIM CNAME record when none exists', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [],
        ]),
        $request->method() === 'POST' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => ['id' => 42],
        ]),
        default => Http::response(['unexpected' => $request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('abc._domainkey.example.com', 'CNAME', ['abc.dkim.amazonses.com'], 300);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }
        $body = $request->data();

        return $body['source'] === 'abc._domainkey'
            && $body['type'] === 'CNAME'
            && $body['target'] === 'abc.dkim.amazonses.com'
            && $body['ttl'] === 300;
    });
});

it('updates a CNAME in place when target differs', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [[
                'id' => 7, 'source' => 'abc._domainkey', 'type' => 'CNAME',
                'target' => 'old.dkim.amazonses.com', 'ttl' => 300, 'description' => [],
            ]],
        ]),
        $request->method() === 'PUT' && str_contains($request->url(), '/2/zones/example.com/records/7') => Http::response([
            'result' => 'success', 'data' => ['id' => 7],
        ]),
        default => Http::response(['unexpected' => $request->method().' '.$request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('abc._domainkey.example.com', 'CNAME', ['new.dkim.amazonses.com'], 300);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/records/7')
        && $request->data()['target'] === 'new.dkim.amazonses.com');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('is a no-op when the desired record already exists with the same target', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [[
                'id' => 9, 'source' => 'abc._domainkey', 'type' => 'CNAME',
                'target' => 'abc.dkim.amazonses.com.', 'ttl' => 300, 'description' => [],
            ]],
        ]),
        default => Http::response(['unexpected' => $request->method().' '.$request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('abc._domainkey.example.com', 'CNAME', ['abc.dkim.amazonses.com'], 300);

    Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
});

it('encodes MX target as "<priority> <hostname>" (v2 API write format)', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [],
        ]),
        $request->method() === 'POST' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => ['id' => 1],
        ]),
        default => Http::response(['unexpected' => $request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('bounce.example.com', 'MX', ['10 feedback-smtp.eu-central-1.amazonses.com'], 300);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }
        $b = $request->data();

        return $b['type'] === 'MX'
            && $b['source'] === 'bounce'
            && $b['target'] === '10 feedback-smtp.eu-central-1.amazonses.com'
            && ! isset($b['description']);
    });
});

it('no-ops MX when existing record (split form from GET) matches desired full form', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [[
                'id' => 11, 'source' => 'bounce', 'type' => 'MX',
                'target' => 'feedback-smtp.eu-central-1.amazonses.com',
                'ttl' => 300,
                'description' => ['priority' => ['value' => 10]],
            ]],
        ]),
        default => Http::response(['unexpected' => $request->method().' '.$request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('bounce.example.com', 'MX', ['10 feedback-smtp.eu-central-1.amazonses.com'], 300);

    Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
});

it('strips surrounding quotes from TXT values when sending to the API', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [],
        ]),
        $request->method() === 'POST' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => ['id' => 1],
        ]),
        default => Http::response(['unexpected' => $request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('_dmarc.example.com', 'TXT', ['"v=DMARC1; p=none;"'], 300);

    Http::assertSent(function (Request $request): bool {
        $b = $request->data();

        return $request->method() === 'POST'
            && $b['type'] === 'TXT'
            && $b['source'] === '_dmarc'
            && $b['target'] === 'v=DMARC1; p=none;';
    });
});

it('deletes leftover records when the desired set is smaller', function () {
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response([
            'result' => 'success', 'data' => [
                ['id' => 1, 'source' => 'abc._domainkey', 'type' => 'CNAME', 'target' => 'keep.dkim.amazonses.com', 'ttl' => 300, 'description' => []],
                ['id' => 2, 'source' => 'abc._domainkey', 'type' => 'CNAME', 'target' => 'stale.dkim.amazonses.com', 'ttl' => 300, 'description' => []],
            ],
        ]),
        $request->method() === 'DELETE' && str_contains($request->url(), '/2/zones/example.com/records/2') => Http::response([
            'result' => 'success',
        ]),
        default => Http::response(['unexpected' => $request->method().' '.$request->url()], 500),
    });

    $api = new InfomaniakDnsApi('tok');
    $api->upsertRecord('abc._domainkey.example.com', 'CNAME', ['keep.dkim.amazonses.com'], 300);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/records/2'));
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('throws when the API returns an error status', function () {
    Http::fake([
        'api.infomaniak.com/2/domains/_dmarc.example.com/zones' => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        'api.infomaniak.com/2/zones/example.com/records*' => Http::response(['error' => ['code' => 'forbidden']], 403),
    ]);

    $api = new InfomaniakDnsApi('tok');

    $api->upsertRecord('_dmarc.example.com', 'TXT', ['"v=DMARC1; p=none;"'], 300);
})->throws(RuntimeException::class, 'HTTP 403');

it('sends the bearer token on every request', function () {
    Http::fake([
        '*' => Http::response(['result' => 'success', 'data' => [['fqdn' => 'example.com']]]),
    ]);

    $api = new InfomaniakDnsApi('secret-token');
    $api->resolveZone('example.com');

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer secret-token'));
});

/**
 * @param  array<string, mixed>  $listBody
 */
function fakeZoneForCreateIfMissing(array $listBody, array $postBody = ['result' => 'success', 'data' => ['id' => 5]]): void
{
    Http::fake(fn (Request $request) => match (true) {
        $request->method() === 'GET' && (bool) preg_match('#/2/domains/[^/]+/zones#', $request->url()) => Http::response([
            'result' => 'success', 'data' => [['fqdn' => 'example.com']],
        ]),
        $request->method() === 'GET' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response(
            match (true) {
                str_contains($request->url(), 'page=2') => $listBody['__page2'] ?? ['result' => 'success', 'data' => []],
                str_contains($request->url(), 'page=3') => $listBody['__page3'] ?? ['result' => 'success', 'data' => []],
                default => array_diff_key($listBody, ['__page2' => true, '__page3' => true]),
            }
        ),
        $request->method() === 'POST' && str_contains($request->url(), '/2/zones/example.com/records') => Http::response($postBody),
        default => Http::response(['unexpected' => $request->method().' '.$request->url()], 500),
    });
}

it('createRecordIfMissing creates the record when the name is free', function () {
    fakeZoneForCreateIfMissing(['result' => 'success', 'data' => [
        ['id' => 1, 'source' => '@', 'type' => 'TXT', 'target' => 'v=spf1 -all', 'ttl' => 300, 'description' => []],
    ]]);

    $created = new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.example.com', 'TXT', ['"v=DMARC1; p=reject;"'], 3600);

    expect($created)->toBeTrue();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->data() === ['source' => '_dmarc', 'type' => 'TXT', 'ttl' => 3600, 'target' => 'v=DMARC1; p=reject;']);
});

it('createRecordIfMissing leaves an existing record of any type untouched', function (string $type, string $source) {
    fakeZoneForCreateIfMissing(['result' => 'success', 'data' => [
        ['id' => 9, 'source' => $source, 'type' => $type, 'target' => 'v=DMARC1; p=quarantine; rua=mailto:x@example.org', 'ttl' => 3600, 'description' => []],
    ]]);

    $created = new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.example.com', 'TXT', ['"v=DMARC1; p=none;"']);

    expect($created)->toBeFalse();
    Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
})->with([
    'TXT' => ['TXT', '_dmarc'],
    'CNAME' => ['CNAME', '_dmarc'],
    'upper-case source' => ['TXT', '_DMARC'],
]);

it('createRecordIfMissing finds an existing record on a later page', function () {
    fakeZoneForCreateIfMissing([
        'result' => 'success', 'pages' => 2, 'total' => 2,
        'data' => [['id' => 1, 'source' => 'www', 'type' => 'A', 'target' => '1.2.3.4', 'ttl' => 300, 'description' => []]],
        '__page2' => ['result' => 'success', 'pages' => 2, 'total' => 2,
            'data' => [['id' => 2, 'source' => '_dmarc', 'type' => 'TXT', 'target' => 'v=DMARC1; p=reject;', 'ttl' => 300, 'description' => []]]],
    ]);

    expect(new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none;']))->toBeFalse();
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('createRecordIfMissing keeps the pagination hints of page 1 when a later page omits them', function () {
    fakeZoneForCreateIfMissing([
        'result' => 'success', 'pages' => 3, 'total' => 3,
        'data' => [['id' => 1, 'source' => 'www', 'type' => 'A', 'target' => '1.2.3.4', 'ttl' => 300, 'description' => []]],
        '__page2' => ['result' => 'success',
            'data' => [['id' => 2, 'source' => 'mail', 'type' => 'A', 'target' => '1.2.3.5', 'ttl' => 300, 'description' => []]]],
        '__page3' => ['result' => 'success',
            'data' => [['id' => 3, 'source' => '_dmarc', 'type' => 'TXT', 'target' => 'v=DMARC1; p=reject;', 'ttl' => 300, 'description' => []]]],
    ]);

    expect(new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none;']))->toBeFalse();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), 'page=3'));
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('createRecordIfMissing refuses to write when the listing is not trustworthy', function (array $listBody, string $message) {
    fakeZoneForCreateIfMissing($listBody);

    expect(fn () => new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none;']))
        ->toThrow(RuntimeException::class, $message);
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
})->with([
    'result error' => [['result' => 'error', 'error' => ['code' => 'x']], 'did not report success'],
    'no result' => [['data' => []], 'did not report success'],
    'missing data' => [['result' => 'success'], 'returned no record list'],
    'data not a list' => [['result' => 'success', 'data' => ['id' => 1]], 'returned no record list'],
    'incomplete by total' => [['result' => 'success', 'total' => 5, 'data' => []], 'incomplete'],
    'record without source' => [['result' => 'success', 'data' => [['id' => 7, 'type' => 'TXT']]], 'without a source'],
    'record not an object' => [['result' => 'success', 'data' => [false]], 'without a source'],
    'record with empty source' => [['result' => 'success', 'data' => [['id' => 7, 'source' => ' ', 'type' => 'TXT']]], 'without a source'],
]);

it('createRecordIfMissing throws when the create is not confirmed', function () {
    fakeZoneForCreateIfMissing(['result' => 'success', 'data' => []], ['result' => 'error', 'error' => ['code' => 'invalid']]);

    expect(fn () => new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none;']))
        ->toThrow(RuntimeException::class, 'did not report success');
});

it('createRecordIfMissing throws when no zone covers the name', function () {
    Http::fake(['*' => Http::response([], 404)]);

    expect(fn () => new InfomaniakDnsApi('tok')->createRecordIfMissing('_dmarc.unknown.example', 'TXT', ['v=DMARC1; p=none;']))
        ->toThrow(RuntimeException::class, 'zone not found');
});
