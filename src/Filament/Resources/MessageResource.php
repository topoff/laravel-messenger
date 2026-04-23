<?php

namespace Topoff\Messenger\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Topoff\Messenger\Filament\Resources\MessageResource\Pages;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Tracking\MessageResender;

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-envelope';

    protected static string | \UnitEnum | null $navigationGroup = 'Messenger';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make('Message')
                    ->schema([
                        Forms\Components\TextInput::make('message_type_id')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('channel'),
                        Forms\Components\TextInput::make('locale'),
                        Forms\Components\TextInput::make('receiver_type'),
                        Forms\Components\TextInput::make('receiver_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('sender_type'),
                        Forms\Components\TextInput::make('sender_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('company_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('messagable_type'),
                        Forms\Components\TextInput::make('messagable_id')
                            ->numeric(),
                    ])->columns(2),

                Forms\Components\Section::make('Params')
                    ->schema([
                        Forms\Components\KeyValue::make('params')
                            ->columnSpanFull(),
                    ])->collapsed(),

                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\TextInput::make('attempts')
                            ->numeric(),
                        Forms\Components\TextInput::make('error_code')
                            ->numeric(),
                        Forms\Components\TextInput::make('error_message'),
                        Forms\Components\DateTimePicker::make('scheduled_at'),
                        Forms\Components\DateTimePicker::make('reserved_at'),
                        Forms\Components\DateTimePicker::make('sent_at'),
                        Forms\Components\DateTimePicker::make('error_at'),
                        Forms\Components\DateTimePicker::make('failed_at'),
                    ])->columns(2),

                Forms\Components\Section::make('Tracking')
                    ->schema([
                        Forms\Components\TextInput::make('tracking_subject'),
                        Forms\Components\TextInput::make('tracking_sender_contact'),
                        Forms\Components\TextInput::make('tracking_sender_name'),
                        Forms\Components\TextInput::make('tracking_recipient_contact'),
                        Forms\Components\TextInput::make('tracking_recipient_name'),
                        Forms\Components\TextInput::make('tracking_message_id'),
                        Forms\Components\TextInput::make('tracking_hash'),
                        Forms\Components\TextInput::make('tracking_opens')
                            ->numeric(),
                        Forms\Components\TextInput::make('tracking_clicks')
                            ->numeric(),
                        Forms\Components\DateTimePicker::make('tracking_opened_at'),
                        Forms\Components\DateTimePicker::make('tracking_clicked_at'),
                        Forms\Components\TextInput::make('tracking_content_path'),
                        Forms\Components\KeyValue::make('tracking_meta')
                            ->columnSpanFull(),
                    ])->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('message_type_id')
                    ->label('Type')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('tracking_sender_contact')
                    ->label('Sender')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tracking_recipient_contact')
                    ->label('Recipient')
                    ->searchable(),
                Tables\Columns\TextColumn::make('locale'),
                Tables\Columns\TextColumn::make('company_id')
                    ->label('Company')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tracking_opens')
                    ->label('Opens')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_clicks')
                    ->label('Clicks')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('attempts')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('failed_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
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
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'scheduled_at' => 'Scheduled',
                        'reserved_at' => 'Reserved',
                        'sent_at' => 'Sent',
                        'error_at' => 'Error',
                        'failed_at' => 'Failed',
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;

                        return $value ? $query->whereNotNull($value) : $query;
                    }),
                Tables\Filters\SelectFilter::make('channel')
                    ->options(fn () => Cache::remember('messenger.channels', now()->addDays(7), fn () => Message::query()->distinct()->pluck('channel', 'channel')->toArray())),
                Tables\Filters\SelectFilter::make('message_type_id')
                    ->label('Message Type')
                    ->relationship('messageType', 'notification_class'),
                Tables\Filters\SelectFilter::make('receiver_type')
                    ->options(fn () => Cache::remember('messenger.receiver_types', now()->addDay(), fn () => Message::query()->whereNotNull('receiver_type')->distinct()->pluck('receiver_type', 'receiver_type')->toArray())),
                Tables\Filters\SelectFilter::make('messagable_type')
                    ->label('Messageable Type')
                    ->options(fn () => Cache::remember('messenger.messageable_types', now()->addDay(), fn () => Message::query()->whereNotNull('messagable_type')->distinct()->pluck('messagable_type', 'messagable_type')->toArray())),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Actions\Action::make('show_sent')
                    ->label('Show Sent')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Message $record) => URL::temporarySignedRoute(
                        'messenger.tracking.nova.preview',
                        now()->addMinutes(15),
                        ['id' => $record->id]
                    ))
                    ->openUrlInNewTab(),
                Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->url(fn (Message $record) => URL::temporarySignedRoute(
                        'messenger.tracking.nova.preview-message',
                        now()->addMinutes(10),
                        ['message' => $record->id]
                    ))
                    ->openUrlInNewTab(),
                Actions\Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Resend as new message?')
                    ->modalDescription('This will queue the message for resending. Messages with errors will be soft-deleted to avoid duplicates.')
                    ->action(function (Message $record) {
                        if ($record->sent_at === null && $record->error_at === null && $record->failed_at === null) {
                            Notification::make()->danger()->title('Only messages with sent_at, error_at, or failed_at can be resent.')->send();

                            return;
                        }

                        app(MessageResender::class)->resend($record);
                        if ($record->sent_at === null && ($record->error_at !== null || $record->failed_at !== null)) {
                            $record->delete();
                        }

                        Notification::make()->success()->title('Message queued for resending.')->send();
                    }),
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\BulkAction::make('resend_bulk')
                    ->label('Resend as new')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $queued = 0;
                        $skipped = 0;
                        $resender = app(MessageResender::class);

                        foreach ($records as $message) {
                            if ($message->sent_at === null && $message->error_at === null && $message->failed_at === null) {
                                $skipped++;

                                continue;
                            }

                            $resender->resend($message);
                            if ($message->sent_at === null && ($message->error_at !== null || $message->failed_at !== null)) {
                                $message->delete();
                            }
                            $queued++;
                        }

                        if ($queued === 0) {
                            Notification::make()->danger()->title('No messages could be resent.')->send();

                            return;
                        }

                        Notification::make()->success()->title("{$queued} resend(s) queued" . ($skipped > 0 ? ", {$skipped} skipped." : '.'))->send();
                    }),
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SoftDeletingScope::class);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessages::route('/'),
            'create' => Pages\CreateMessage::route('/create'),
            'edit' => Pages\EditMessage::route('/{record}/edit'),
            'tracking-by-type' => Pages\TrackingByType::route('/tracking-by-type'),
            'tracking-by-domain' => Pages\TrackingByDomain::route('/tracking-by-domain'),
        ];
    }
}