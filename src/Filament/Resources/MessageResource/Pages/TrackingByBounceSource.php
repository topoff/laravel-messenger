<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Topoff\Messenger\Filament\Resources\MessageResource;
use Topoff\Messenger\Models\Message;

/**
 * Filament counterpart to the Nova MessagesByBounceSourceLens. Aggregates the
 * 30-day window of bounced messages by their reporting source (sns / imap /
 * both / unknown), counts accept-then-bounce occurrences, and reports the
 * first/last bounce timestamp per source.
 */
class TrackingByBounceSource extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = MessageResource::class;

    protected string $view = 'messenger::filament.pages.tracking-table';

    protected static ?string $title = 'Bounces by Source';

    public function table(Table $table): Table
    {
        $messageTable = (new Message)->getTable();
        $sourceExpr = self::bounceSourceExpression("{$messageTable}.tracking_meta");

        return $table
            ->query(
                Message::query()
                    ->select([
                        DB::raw("{$sourceExpr} as bounce_source"),
                        DB::raw('COUNT(*) as total_bounced'),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.delivered_at IS NOT NULL THEN 1 END) as with_prior_delivery"),
                        DB::raw("MIN({$messageTable}.bounced_at) as first_bounced_at"),
                        DB::raw("MAX({$messageTable}.bounced_at) as last_bounced_at"),
                    ])
                    ->whereNotNull("{$messageTable}.bounced_at")
                    ->whereBetween("{$messageTable}.created_at", [Date::today()->subDays(30)->startOfDay(), Date::today()->endOfDay()])
                    ->groupBy('bounce_source')
            )
            ->columns([
                Tables\Columns\TextColumn::make('bounce_source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sns' => 'success',
                        'imap' => 'warning',
                        'both' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_bounced')
                    ->label('Bounced Messages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('with_prior_delivery')
                    ->label('Accept-then-bounce')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_bounced_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_bounced_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('total_bounced', 'desc')
            ->paginated(false);
    }

    /**
     * Mirrors MessagesByBounceSourceLens::bounceSourceExpression. Duplicated to
     * avoid loading the Nova lens class from the Filament page.
     */
    public static function bounceSourceExpression(string $column): string
    {
        $driver = DB::getDriverName();
        $hasImap = self::jsonKeyPresent($driver, $column, 'imap_message_bounce');
        $hasSns = self::jsonKeyPresent($driver, $column, 'sns_message_bounce');

        return <<<SQL
            CASE
                WHEN ({$hasImap}) AND ({$hasSns}) THEN 'both'
                WHEN ({$hasImap}) THEN 'imap'
                WHEN ({$hasSns}) THEN 'sns'
                ELSE 'unknown'
            END
        SQL;
    }

    private static function jsonKeyPresent(string $driver, string $column, string $key): string
    {
        return match ($driver) {
            'pgsql' => "({$column}->>'{$key}') IS NOT NULL",
            default => "JSON_EXTRACT({$column}, '$.{$key}') IS NOT NULL",
        };
    }
}
