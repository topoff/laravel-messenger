<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Topoff\Messenger\Filament\Resources\MessageResource;
use Topoff\Messenger\Models\Message;

class TrackingByDomain extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = MessageResource::class;

    protected string $view = 'messenger::filament.pages.tracking-table';

    protected static ?string $title = 'Tracking Metrics by Domain';

    public function table(Table $table): Table
    {
        $messageTable = (new Message)->getTable();
        $domainExpr = self::domainExpression("{$messageTable}.tracking_recipient_contact");

        return $table
            ->query(
                Message::query()
                    ->select([
                        DB::raw("{$domainExpr} as domain"),
                        DB::raw('COUNT(*) as total_messages'),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END) as total_sent"),
                        DB::raw("SUM({$messageTable}.tracking_opens) as total_opens"),
                        DB::raw("SUM({$messageTable}.tracking_clicks) as total_clicks"),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) as unique_opened"),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) as unique_clicked"),
                        DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0), 2) as open_rate"),
                        DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0), 2) as click_rate"),
                    ])
                    ->whereNotNull("{$messageTable}.tracking_recipient_contact")
                    ->whereBetween("{$messageTable}.created_at", [Date::today()->subDays(30)->startOfDay(), Date::today()->endOfDay()])
                    ->groupBy('domain')
            )
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_messages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_sent')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_opens')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_clicks')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unique_opened')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unique_clicked')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('open_rate')
                    ->label('Open Rate %')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('click_rate')
                    ->label('Click Rate %')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('total_messages', 'desc')
            ->paginated(false);
    }

    public static function domainExpression(string $column): string
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => "SUBSTRING_INDEX({$column}, '@', -1)",
            'pgsql' => "SPLIT_PART({$column}, '@', 2)",
            default => "SUBSTR({$column}, INSTR({$column}, '@') + 1)",
        };
    }
}
