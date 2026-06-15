<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Topoff\Messenger\Filament\Resources\MessageResource;
use Topoff\Messenger\Models\Message;

/**
 * Filament counterpart to the Nova CompanyTrackingMetricsLens. Aggregates the
 * tracking metrics (sent, opens, clicks, rates) per company_id.
 */
class CompanyTrackingMetrics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = MessageResource::class;

    protected string $view = 'messenger::filament.pages.tracking-table';

    protected static ?string $title = 'Company Tracking Metrics';

    public function table(Table $table): Table
    {
        $messageTable = (new Message)->getTable();

        return $table
            ->query(
                Message::query()
                    ->withoutGlobalScope(SoftDeletingScope::class)
                    ->select([
                        "{$messageTable}.company_id",
                        DB::raw('COUNT(*) as total_messages'),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END) as total_sent"),
                        DB::raw("SUM({$messageTable}.tracking_opens) as total_opens"),
                        DB::raw("SUM({$messageTable}.tracking_clicks) as total_clicks"),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) as unique_opened"),
                        DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) as unique_clicked"),
                        DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0), 2) as open_rate"),
                        DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0), 2) as click_rate"),
                    ])
                    ->whereNotNull("{$messageTable}.company_id")
                    ->groupBy("{$messageTable}.company_id")
            )
            ->columns([
                Tables\Columns\TextColumn::make('company_id')
                    ->label('Company ID')
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
            ->filters($this->buildFilters($messageTable))
            ->defaultSort('total_messages', 'desc')
            ->paginated(false);
    }

    /**
     * @return array<int, mixed>
     */
    protected function buildFilters(string $messageTable): array
    {
        $filters = [
            Tables\Filters\SelectFilter::make('date_range')
                ->label('Date Range')
                ->default('30-days')
                ->options([
                    'today' => 'Today',
                    '7-days' => 'Last 7 Days',
                    '30-days' => 'Last 30 Days',
                    'month' => 'This Month',
                    'last-month' => 'Last Month',
                    'this-year' => 'This Year',
                    'all-time' => 'All Time',
                ])
                ->query(function ($query, array $data) use ($messageTable) {
                    $value = $data['value'] ?? null;
                    $column = "{$messageTable}.created_at";

                    return match ($value) {
                        'today' => $query->whereBetween($column, [Date::today()->startOfDay(), Date::today()->endOfDay()]),
                        '7-days' => $query->whereBetween($column, [Date::today()->subDays(7)->startOfDay(), Date::today()->endOfDay()]),
                        '30-days' => $query->whereBetween($column, [Date::today()->subDays(30)->startOfDay(), Date::today()->endOfDay()]),
                        'month' => $query->whereBetween($column, [Date::today()->startOfMonth(), Date::today()->endOfMonth()]),
                        'last-month' => $query->whereBetween($column, [Date::today()->subMonthNoOverflow()->startOfMonth(), Date::today()->subMonthNoOverflow()->endOfMonth()]),
                        'this-year' => $query->whereBetween($column, [Date::today()->startOfYear(), Date::today()->endOfYear()]),
                        default => $query,
                    };
                }),
            Tables\Filters\SelectFilter::make('company_id')
                ->label('Company')
                ->options(fn (): array => DB::connection(config('messenger.database.connection'))
                    ->table($messageTable)
                    ->whereNotNull('company_id')
                    ->distinct()
                    ->orderBy('company_id')
                    ->pluck('company_id', 'company_id')
                    ->mapWithKeys(fn ($id): array => ["{$id}" => (string) $id])
                    ->toArray())
                ->query(function ($query, array $data) use ($messageTable) {
                    $value = $data['value'] ?? null;

                    return $value ? $query->where("{$messageTable}.company_id", $value) : $query;
                }),
        ];

        // The company status / deleted filters depend on a host `companies` table.
        // Add them only when it exists so the package stays usable elsewhere.
        $connection = config('messenger.database.connection');
        if (Schema::connection($connection)->hasTable('companies')) {
            $filters[] = Tables\Filters\SelectFilter::make('company_status')
                ->label('Company Status')
                ->options(fn (): array => $this->companyStatusOptions($connection))
                ->query(function ($query, array $data) use ($messageTable) {
                    $value = $data['value'] ?? null;
                    if ($value === null || $value === '') {
                        return $query;
                    }

                    return $query->whereIn("{$messageTable}.company_id", function ($sub) use ($value): void {
                        $sub->select('id')->from('companies')->where('status', $value);
                    });
                });

            if (Schema::connection($connection)->hasColumn('companies', 'deleted_at')) {
                $filters[] = Tables\Filters\SelectFilter::make('company_deleted')
                    ->label('Company deleted_at')
                    ->options([
                        'active' => 'Active (null)',
                        'deleted' => 'Deleted (not null)',
                    ])
                    ->query(function ($query, array $data) use ($messageTable): mixed {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'deleted' => $query->whereIn("{$messageTable}.company_id", fn ($sub) => $sub->select('id')->from('companies')->whereNotNull('deleted_at')),
                            'active' => $query->whereIn("{$messageTable}.company_id", fn ($sub) => $sub->select('id')->from('companies')->whereNull('deleted_at')),
                            default => $query,
                        };
                    });
            }
        }

        return $filters;
    }

    /**
     * @return array<string, int>
     */
    protected function companyStatusOptions(?string $connection): array
    {
        $statuses = DB::connection($connection)
            ->table('companies')
            ->select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $enum = 'App\\Enums\\CompanyStatus';

        return $statuses
            ->mapWithKeys(function ($status) use ($enum): array {
                $label = class_exists($enum) && method_exists($enum, 'fromValue')
                    ? $enum::fromValue($status)->description
                    : (string) $status;

                return [$label => $status];
            })
            ->toArray();
    }
}
