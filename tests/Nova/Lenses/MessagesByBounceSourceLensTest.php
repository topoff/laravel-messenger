<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->messageType = createMessageType();
});

it('groups bounced messages by source: sns, imap, both, unknown', function () {
    // SNS-only bounce
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => now(),
        'tracking_meta' => ['sns_message_bounce' => ['notificationType' => 'Bounce']],
    ]);
    // IMAP-only bounce
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => now(),
        'tracking_meta' => ['imap_message_bounce' => ['classification' => 'hard_bounce']],
    ]);
    // Bounced through both pipes
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => now(),
        'tracking_meta' => [
            'sns_message_bounce' => ['notificationType' => 'Bounce'],
            'imap_message_bounce' => ['classification' => 'hard_bounce'],
        ],
    ]);
    // Legacy bounced row without source markers
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => now(),
        'tracking_meta' => ['failures' => []],
    ]);
    // Non-bounced — must be excluded
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => null,
        'tracking_meta' => null,
    ]);

    $results = bounceSourceQuery()->get();

    $bySource = $results->keyBy('bounce_source');

    expect($bySource)->toHaveCount(4);
    expect((int) $bySource['sns']->total_bounced)->toBe(1);
    expect((int) $bySource['imap']->total_bounced)->toBe(1);
    expect((int) $bySource['both']->total_bounced)->toBe(1);
    expect((int) $bySource['unknown']->total_bounced)->toBe(1);
});

it('counts messages that bounced after a successful delivery as with_prior_delivery', function () {
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => now(),
        'delivered_at' => now()->subHour(),
        'tracking_meta' => ['sns_message_bounce' => ['notificationType' => 'Bounce']],
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'bounced_at' => now(),
        'delivered_at' => null,
        'tracking_meta' => ['sns_message_bounce' => ['notificationType' => 'Bounce']],
    ]);

    $row = bounceSourceQuery()->get()->firstWhere('bounce_source', 'sns');

    expect((int) $row->total_bounced)->toBe(2)
        ->and((int) $row->with_prior_delivery)->toBe(1);
});

/**
 * Database-portable expression mirroring MessagesByBounceSourceLens::bounceSourceExpression.
 * Duplicated here so the test does not need to load the Nova lens class (which requires
 * Laravel Nova to be installed).
 */
function bounceSourceExpression(string $column): string
{
    $driver = DB::getDriverName();
    $hasImap = bounceJsonPresent($driver, $column, 'imap_message_bounce');
    $hasSns = bounceJsonPresent($driver, $column, 'sns_message_bounce');

    return <<<SQL
        CASE
            WHEN ({$hasImap}) AND ({$hasSns}) THEN 'both'
            WHEN ({$hasImap}) THEN 'imap'
            WHEN ({$hasSns}) THEN 'sns'
            ELSE 'unknown'
        END
    SQL;
}

function bounceJsonPresent(string $driver, string $column, string $key): string
{
    return match ($driver) {
        'pgsql' => "({$column}->>'{$key}') IS NOT NULL",
        default => "JSON_EXTRACT({$column}, '$.{$key}') IS NOT NULL",
    };
}

function bounceSourceQuery(): Builder
{
    $table = (new (config('messenger.models.message')))->getTable();
    $expr = bounceSourceExpression("{$table}.tracking_meta");

    return DB::table($table)
        ->select([
            DB::raw("{$expr} as bounce_source"),
            DB::raw('COUNT(*) as total_bounced'),
            DB::raw("COUNT(CASE WHEN {$table}.delivered_at IS NOT NULL THEN 1 END) as with_prior_delivery"),
            DB::raw("MIN({$table}.bounced_at) as first_bounced_at"),
            DB::raw("MAX({$table}.bounced_at) as last_bounced_at"),
        ])
        ->whereNotNull("{$table}.bounced_at")
        ->groupBy('bounce_source');
}
