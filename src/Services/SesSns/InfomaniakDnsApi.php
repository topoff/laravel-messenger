<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\SesSns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin Infomaniak DNS API wrapper for SES record management.
 *
 * Endpoints used:
 *   GET  /2/domains/{domain}/zones                       — discover the managed zone FQDN
 *   GET  /2/zones/{zone}/records                         — list records in a zone
 *   POST /2/zones/{zone}/records                         — create a record
 *   PUT  /2/zones/{zone}/records/{recordId}              — update a record
 *   DELETE /2/zones/{zone}/records/{recordId}            — delete a record
 *
 * Source values posted to the API are RELATIVE to the zone (e.g. `_dmarc`, not
 * `_dmarc.example.com`). TXT values are sent UNQUOTED. MX records are posted with
 * the priority inline in `target` (e.g. `target: "10 mail.example.com"`); on GET
 * Infomaniak splits that back into `target` + `description.priority.value`, which
 * recordMatches() reconciles when comparing existing vs desired records.
 */
class InfomaniakDnsApi
{
    /** @var array<string, ?string> */
    protected array $zoneCache = [];

    public function __construct(
        protected string $token,
        protected string $baseUrl = 'https://api.infomaniak.com',
    ) {}

    /**
     * Upsert a single DNS record by (recordFqdn, type). Reconciles with whatever
     * records already exist at that name+type pair — adds missing values, updates
     * a stale single record in place, deletes extras when desired set is smaller.
     *
     * Each value in $values becomes one Infomaniak record. MX values must be in
     * the form "<priority> <target>"; TXT values may be supplied with or without
     * surrounding quotes (quotes are stripped).
     *
     * @param  array<int, string>  $values
     */
    public function upsertRecord(string $recordFqdn, string $type, array $values, int $ttl = 300): void
    {
        $zone = $this->resolveZone($recordFqdn);
        if ($zone === null) {
            throw new RuntimeException('Infomaniak zone not found for: '.$recordFqdn);
        }

        $type = strtoupper($type);
        $source = $this->relativeSource($recordFqdn, $zone);
        $desired = array_values(array_map(fn (string $v): array => $this->normalizeValue($type, $v), $values));

        $existing = array_values(array_filter(
            $this->listRecords($zone),
            fn (array $r): bool => strtoupper((string) $r['type']) === $type
                && $this->normalizeSource($r['source']) === $this->normalizeSource($source),
        ));

        // Match existing records by target (and priority for MX). Whatever's left is "extras".
        $matchedIds = [];
        $stillDesired = [];
        foreach ($desired as $want) {
            $hit = null;
            foreach ($existing as $r) {
                if (in_array($r['id'], $matchedIds, true)) {
                    continue;
                }
                if ($this->recordMatches($r, $want)) {
                    $hit = $r;
                    break;
                }
            }
            if ($hit !== null) {
                $matchedIds[] = $hit['id'];
            } else {
                $stillDesired[] = $want;
            }
        }
        $extras = array_values(array_filter($existing, fn (array $r): bool => ! in_array($r['id'], $matchedIds, true)));

        // Reuse extras as in-place updates before falling back to create/delete.
        foreach ($stillDesired as $want) {
            $reuse = array_shift($extras);
            if ($reuse !== null) {
                $this->updateRecord($zone, (int) $reuse['id'], array_merge(
                    ['source' => $source, 'type' => $type, 'ttl' => $ttl],
                    $want,
                ));
            } else {
                $this->createRecord($zone, array_merge(
                    ['source' => $source, 'type' => $type, 'ttl' => $ttl],
                    $want,
                ));
            }
        }

        foreach ($extras as $stale) {
            $this->deleteRecord($zone, (int) $stale['id']);
        }
    }

    /**
     * Ask Infomaniak which managed zone covers the given FQDN. The API returns all
     * zones associated with the domain — we pick the longest FQDN that is a suffix
     * of the input (i.e. the most specific zone, in case the account hosts both an
     * apex zone and a subdomain zone).
     *
     * Returns the zone FQDN (e.g. "example.com") or null when no zone matches.
     */
    public function resolveZone(string $domain): ?string
    {
        $key = strtolower(rtrim($domain, '.'));
        if (array_key_exists($key, $this->zoneCache)) {
            return $this->zoneCache[$key];
        }

        $response = $this->client()->get("/2/domains/{$key}/zones");
        if ($response->status() === 404) {
            return $this->zoneCache[$key] = null;
        }
        $this->assertOk($response, "Infomaniak zone lookup for {$key}");

        $zones = (array) ($response->json('data') ?? []);
        $matches = array_values(array_filter(
            array_map(static fn (array $z): string => strtolower((string) ($z['fqdn'] ?? '')), $zones),
            static fn (string $fqdn): bool => $fqdn !== '' && ($fqdn === $key || str_ends_with($key, '.'.$fqdn)),
        ));
        usort($matches, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        return $this->zoneCache[$key] = $matches[0] ?? null;
    }

    /**
     * @return array<int, array{id: int, source: string, type: string, target: string, ttl: int, description: array<string, mixed>}>
     */
    public function listRecords(string $zoneFqdn): array
    {
        $response = $this->client()->get("/2/zones/{$zoneFqdn}/records", ['with' => 'records_description']);
        $this->assertOk($response, "Infomaniak record list for {$zoneFqdn}");

        $records = (array) ($response->json('data') ?? []);

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) ($r['id'] ?? 0),
            'source' => (string) ($r['source'] ?? ''),
            'type' => (string) ($r['type'] ?? ''),
            'target' => (string) ($r['target'] ?? ''),
            'ttl' => (int) ($r['ttl'] ?? 0),
            'description' => (array) ($r['description'] ?? []),
        ], $records));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRecord(string $zoneFqdn, array $payload): int
    {
        $response = $this->client()->post("/2/zones/{$zoneFqdn}/records", $payload);
        $this->assertOk($response, "Infomaniak record create in {$zoneFqdn}");

        return (int) ($response->json('data.id') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateRecord(string $zoneFqdn, int $recordId, array $payload): void
    {
        $response = $this->client()->put("/2/zones/{$zoneFqdn}/records/{$recordId}", $payload);
        $this->assertOk($response, "Infomaniak record update {$recordId} in {$zoneFqdn}");
    }

    public function deleteRecord(string $zoneFqdn, int $recordId): void
    {
        $response = $this->client()->delete("/2/zones/{$zoneFqdn}/records/{$recordId}");
        $this->assertOk($response, "Infomaniak record delete {$recordId} in {$zoneFqdn}");
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->token)
            ->acceptJson()
            ->asJson();
    }

    protected function assertOk(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        throw new RuntimeException(sprintf(
            '%s failed with HTTP %d: %s',
            $context,
            $response->status(),
            (string) $response->body(),
        ));
    }

    /**
     * Build (relative_source, normalized_value) payload bits for a desired record.
     *
     * @return array<string, mixed>
     */
    protected function normalizeValue(string $type, string $value): array
    {
        $value = trim($value);
        if ($type === 'MX') {
            // Infomaniak v2 API expects MX target as the full "<priority> <hostname>"
            // string (priority NOT in description). On GET the API splits it back into
            // target + description.priority — see recordMatches() for the reverse.
            if (preg_match('/^(\d+)\s+(.+)$/', $value, $m) === 1) {
                return ['target' => $m[1].' '.rtrim($m[2], '.')];
            }

            return ['target' => rtrim($value, '.')];
        }

        if ($type === 'TXT') {
            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }

            return ['target' => $value];
        }

        return ['target' => rtrim($value, '.')];
    }

    /**
     * @param  array{type: string, target: string, description?: array<string, mixed>}  $existing
     * @param  array{target: string, description?: array<string, mixed>}  $desired
     */
    protected function recordMatches(array $existing, array $desired): bool
    {
        $type = strtoupper($existing['type']);
        $existingTarget = rtrim(trim($existing['target']), '.');
        $desiredTarget = rtrim(trim($desired['target']), '.');

        if ($type === 'MX') {
            // GET returns target without priority + priority in description; POST/PUT
            // takes target = "<priority> <hostname>". Normalize the existing record to
            // the same shape as desired before comparing.
            $existingPriority = (int) ($existing['description']['priority']['value'] ?? 0);
            $existingFull = $existingPriority.' '.$existingTarget;

            return strcasecmp($existingFull, $desiredTarget) === 0;
        }

        return strcasecmp($existingTarget, $desiredTarget) === 0;
    }

    protected function relativeSource(string $recordFqdn, string $zoneFqdn): string
    {
        $record = strtolower(rtrim($recordFqdn, '.'));
        $zone = strtolower(rtrim($zoneFqdn, '.'));

        if ($record === $zone) {
            return '@';
        }
        if (str_ends_with($record, '.'.$zone)) {
            return substr($record, 0, -strlen('.'.$zone));
        }

        return $record;
    }

    protected function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        $source = rtrim($source, '.');
        if ($source === '' || $source === '@') {
            return '@';
        }

        return $source;
    }
}
