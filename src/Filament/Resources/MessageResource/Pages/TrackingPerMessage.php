<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Date;
use Topoff\Messenger\Filament\Resources\MessageResource;
use Topoff\Messenger\Models\Message;

/**
 * Filament counterpart to the Nova MessagesTrackingLens. Lists individual
 * messages with their per-message tracking detail (opens, clicks, timestamps,
 * SES message id, tracking hash).
 */
class TrackingPerMessage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = MessageResource::class;

    protected string $view = 'messenger::filament.pages.tracking-table';

    protected static ?string $title = 'Tracking Details';

    public function table(Table $table): Table
    {
        return $table
            ->query(Message::query())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('message_type_id')
                    ->label('Message Type Id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_subject')
                    ->label('Subject')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('tracking_sender_contact')
                    ->label('Sender')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_recipient_contact')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_opens')
                    ->label('Opens')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_clicks')
                    ->label('Clicks')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_opened_at')
                    ->label('Opened At')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_clicked_at')
                    ->label('Clicked At')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Sent At')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_message_id')
                    ->label('Message Id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tracking_hash')
                    ->label('Hash')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('date_range')
                    ->label('Date Range')
                    ->options([
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        'week' => 'This Week',
                        'last-week' => 'Last Week',
                        '7-days' => 'Last 7 Days',
                        '30-days' => 'Last 30 Days',
                        'month' => 'This Month',
                        'last-month' => 'Last Month',
                        'this-year' => 'This Year',
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return match ($value) {
                            'today' => $query->whereBetween('created_at', [Date::today()->startOfDay(), Date::today()->endOfDay()]),
                            'yesterday' => $query->whereBetween('created_at', [Date::today()->subDay()->startOfDay(), Date::today()->subDay()->endOfDay()]),
                            'week' => $query->whereBetween('created_at', [Date::today()->startOfWeek(), Date::today()->endOfWeek()]),
                            'last-week' => $query->whereBetween('created_at', [Date::today()->subWeek()->startOfWeek(), Date::today()->subWeek()->endOfWeek()]),
                            '7-days' => $query->whereBetween('created_at', [Date::today()->subDays(7)->startOfDay(), Date::today()->endOfDay()]),
                            '30-days' => $query->whereBetween('created_at', [Date::today()->subDays(30)->startOfDay(), Date::today()->endOfDay()]),
                            'month' => $query->whereBetween('created_at', [Date::today()->startOfMonth(), Date::today()->endOfMonth()]),
                            'last-month' => $query->whereBetween('created_at', [Date::today()->subMonthNoOverflow()->startOfMonth(), Date::today()->subMonthNoOverflow()->endOfMonth()]),
                            'this-year' => $query->whereBetween('created_at', [Date::today()->startOfYear(), Date::today()->endOfYear()]),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
