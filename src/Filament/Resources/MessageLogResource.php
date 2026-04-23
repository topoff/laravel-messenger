<?php

namespace Topoff\Messenger\Filament\Resources;

use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Topoff\Messenger\Filament\Resources\MessageLogResource\Pages;
use Topoff\Messenger\Models\MessageLog;

class MessageLogResource extends Resource
{
    protected static ?string $model = MessageLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Messenger';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make('Message Log')
                    ->schema([
                        Forms\Components\TextInput::make('channel'),
                        Forms\Components\TextInput::make('to'),
                        Forms\Components\TextInput::make('type'),
                        Forms\Components\TextInput::make('subject'),
                        Forms\Components\TextInput::make('cc'),
                        Forms\Components\TextInput::make('bcc'),
                        Forms\Components\Toggle::make('has_attachment'),
                        Forms\Components\TextInput::make('notifyable_id'),
                        Forms\Components\TextInput::make('notification_id'),
                    ])->columns(2),
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
                Tables\Columns\TextColumn::make('to')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('cc')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('bcc')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('has_attachment')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->options(fn () => MessageLog::query()->distinct()->pluck('channel', 'channel')->toArray()),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessageLogs::route('/'),
        ];
    }
}
