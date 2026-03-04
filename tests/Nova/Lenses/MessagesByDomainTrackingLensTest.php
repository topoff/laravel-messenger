<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->messageType = createMessageType();
});

it('groups messages by recipient domain', function () {
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'alice@gmail.com',
        'sent_at' => now(),
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'bob@gmail.com',
        'sent_at' => now(),
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'carol@yahoo.com',
        'sent_at' => now(),
    ]);

    $results = domainTrackingQuery()->get();

    expect($results)->toHaveCount(2);

    $gmail = $results->firstWhere('domain', 'gmail.com');
    $yahoo = $results->firstWhere('domain', 'yahoo.com');

    expect($gmail->total_messages)->toBe(2)
        ->and($yahoo->total_messages)->toBe(1);
});

it('calculates open rate per domain', function () {
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'a@gmail.com',
        'sent_at' => now(),
        'tracking_opened_at' => now(),
        'tracking_opens' => 3,
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'b@gmail.com',
        'sent_at' => now(),
        'tracking_opens' => 0,
    ]);

    $results = domainTrackingQuery()->get();
    $gmail = $results->firstWhere('domain', 'gmail.com');

    expect((float) $gmail->open_rate)->toBe(50.0)
        ->and((int) $gmail->unique_opened)->toBe(1)
        ->and((int) $gmail->total_opens)->toBe(3);
});

it('calculates click rate per domain', function () {
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'a@outlook.com',
        'sent_at' => now(),
        'tracking_clicked_at' => now(),
        'tracking_clicks' => 5,
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'b@outlook.com',
        'sent_at' => now(),
        'tracking_clicks' => 0,
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'c@outlook.com',
        'sent_at' => now(),
        'tracking_clicked_at' => now(),
        'tracking_clicks' => 2,
    ]);

    $results = domainTrackingQuery()->get();
    $outlook = $results->firstWhere('domain', 'outlook.com');

    expect(round((float) $outlook->click_rate, 2))->toBe(66.67)
        ->and((int) $outlook->unique_clicked)->toBe(2)
        ->and((int) $outlook->total_clicks)->toBe(7);
});

it('excludes messages with null tracking_recipient_contact', function () {
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'user@example.com',
        'sent_at' => now(),
    ]);
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => null,
        'sent_at' => now(),
    ]);

    $results = domainTrackingQuery()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->domain)->toBe('example.com');
});

it('handles zero sent messages without division error', function () {
    createMessage([
        'message_type_id' => $this->messageType->id,
        'tracking_recipient_contact' => 'test@nowhere.com',
        'sent_at' => null,
    ]);

    $results = domainTrackingQuery()->get();
    $nowhere = $results->firstWhere('domain', 'nowhere.com');

    expect((int) $nowhere->total_sent)->toBe(0)
        ->and($nowhere->open_rate)->toBeNull()
        ->and($nowhere->click_rate)->toBeNull();
});

/**
 * Database-portable domain extraction expression (mirrors MessagesByDomainTrackingLens::domainExpression).
 */
function domainExpression(string $column): string
{
    $driver = DB::getDriverName();

    return match ($driver) {
        'mysql', 'mariadb' => "SUBSTRING_INDEX({$column}, '@', -1)",
        'pgsql' => "SPLIT_PART({$column}, '@', 2)",
        default => "SUBSTR({$column}, INSTR({$column}, '@') + 1)",
    };
}

/**
 * Helper to build the same aggregation query the lens uses, executed directly via DB::table().
 */
function domainTrackingQuery(): \Illuminate\Database\Query\Builder
{
    $table = (new (config('messenger.models.message')))->getTable();
    $domainExpr = domainExpression("{$table}.tracking_recipient_contact");

    return DB::table($table)
        ->select([
            DB::raw("{$domainExpr} as domain"),
            DB::raw('COUNT(*) as total_messages'),
            DB::raw("COUNT(CASE WHEN {$table}.sent_at IS NOT NULL THEN 1 END) as total_sent"),
            DB::raw("SUM({$table}.tracking_opens) as total_opens"),
            DB::raw("SUM({$table}.tracking_clicks) as total_clicks"),
            DB::raw("COUNT(CASE WHEN {$table}.tracking_opened_at IS NOT NULL THEN 1 END) as unique_opened"),
            DB::raw("COUNT(CASE WHEN {$table}.tracking_clicked_at IS NOT NULL THEN 1 END) as unique_clicked"),
            DB::raw("ROUND(COUNT(CASE WHEN {$table}.tracking_opened_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$table}.sent_at IS NOT NULL THEN 1 END), 0), 2) as open_rate"),
            DB::raw("ROUND(COUNT(CASE WHEN {$table}.tracking_clicked_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$table}.sent_at IS NOT NULL THEN 1 END), 0), 2) as click_rate"),
        ])
        ->whereNotNull("{$table}.tracking_recipient_contact")
        ->groupBy('domain')
        ->orderByDesc('total_messages');
}
