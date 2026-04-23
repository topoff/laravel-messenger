<?php

namespace Topoff\Messenger\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Topoff\Messenger\Filament\Resources\MessageTypeResource\Pages;
use Topoff\Messenger\Models\MessageType;

class MessageTypeResource extends Resource
{
    protected static ?string $model = MessageType::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string | \UnitEnum | null $navigationGroup = 'Messenger';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make('Message Type')
                    ->schema([
                        Forms\Components\TextInput::make('channel')
                            ->required(),
                        Forms\Components\TextInput::make('notification_class')
                            ->required(),
                        Forms\Components\TextInput::make('single_handler'),
                        Forms\Components\TextInput::make('bulk_handler'),
                    ])->columns(2),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('direct'),
                        Forms\Components\Toggle::make('dev_bcc'),
                        Forms\Components\TextInput::make('error_stop_send_minutes')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('max_retry_attempts')
                            ->numeric()
                            ->default(3),
                        Forms\Components\TextInput::make('ses_configuration_set')
                            ->hint('Config key (e.g. "default", "transactional", "marketing")'),
                    ])->columns(2),

                Forms\Components\Section::make('Requirements')
                    ->schema([
                        Forms\Components\Toggle::make('required_sender'),
                        Forms\Components\Toggle::make('required_messagable'),
                        Forms\Components\Toggle::make('required_company_id'),
                        Forms\Components\Toggle::make('required_scheduled'),
                        Forms\Components\Toggle::make('required_text'),
                        Forms\Components\Toggle::make('required_params'),
                    ])->columns(3),

                Forms\Components\Section::make('Bulk')
                    ->schema([
                        Forms\Components\Textarea::make('bulk_message_line')
                            ->columnSpanFull(),
                    ])->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notification_class')
                    ->searchable()
                    ->sortable()
                    ->limit(60),
                Tables\Columns\TextColumn::make('single_handler')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('direct')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('dev_bcc')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_stop_send_minutes')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('max_retry_attempts')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('messages_count')
                    ->counts('messages')
                    ->label('Messages')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->options(fn () => MessageType::query()->distinct()->pluck('channel', 'channel')->toArray()),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Actions\Action::make('preview_message')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->form(fn (MessageType $record) => [
                        Forms\Components\Select::make('message_id')
                            ->label('Message')
                            ->options(function () use ($record) {
                                $messageModelClass = config('messenger.models.message');

                                return $messageModelClass::query()
                                    ->where('message_type_id', $record->id)
                                    ->orderByDesc('created_at')
                                    ->limit(25)
                                    ->get()
                                    ->mapWithKeys(function (Model $message): array {
                                        $createdAt = $message->created_at?->format('Y-m-d H:i');
                                        $recipient = $message->tracking_recipient_contact ?: ($message->receiver_id ? 'receiverId#' . $message->receiver_id : 'n/a');
                                        $locale = $message->locale ?: 'n/a';

                                        return [
                                            (string) $message->id => '#' . $message->id
                                                . ' | ' . $recipient
                                                . ' | ' . $locale
                                                . ' | ' . $createdAt,
                                        ];
                                    })
                                    ->toArray();
                            })
                            ->required()
                            ->helperText('Choose one of the latest 25 messages for preview.'),
                    ])
                    ->action(function (MessageType $record, array $data) {
                        $messageModelClass = config('messenger.models.message');
                        $message = $messageModelClass::query()
                            ->where('id', (int) $data['message_id'])
                            ->where('message_type_id', $record->id)
                            ->first();

                        if (! $message) {
                            Notification::make()->danger()->title('Message not found for this type.')->send();

                            return;
                        }

                        $this->redirect(URL::temporarySignedRoute(
                            'messenger.tracking.nova.preview-message',
                            now()->addMinutes(10),
                            ['message' => $message->id]
                        ));
                    }),
                Actions\EditAction::make(),
            ])
            ->bulkActions([
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
            'index' => Pages\ListMessageTypes::route('/'),
            'create' => Pages\CreateMessageType::route('/create'),
            'edit' => Pages\EditMessageType::route('/{record}/edit'),
        ];
    }
}