<?php

declare(strict_types=1);

namespace Topoff\Messenger\Contracts;

/**
 * Optional capability of a SesSnsProvisioningApi: create a Route53 record only
 * when nothing exists at that name yet. Used for records that must never be
 * overwritten by the DNS automation (e.g. `_dmarc`). Providers without this
 * capability simply do not get such records written.
 */
interface CreatesRoute53RecordsIfMissing
{
    /**
     * Returns true when the record was created, false when any record set (of
     * any type) already exists at $recordName. Never modifies existing records.
     *
     * @param  array<int, string>  $values
     */
    public function createRoute53RecordIfMissing(
        string $hostedZoneId,
        string $recordName,
        string $recordType,
        array $values,
        int $ttl = 300,
    ): bool;
}
