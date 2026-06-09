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

it('encodes MX priority in description, not in target', function () {
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
            && $b['target'] === 'feedback-smtp.eu-central-1.amazonses.com'
            && ($b['description']['priority']['value'] ?? null) === 10;
    });
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
