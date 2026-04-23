<?php

namespace Topoff\Messenger\Filament\Resources\MessageTypeResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Topoff\Messenger\Filament\Resources\MessageTypeResource;

class EditMessageType extends EditRecord
{
    protected static string $resource = MessageTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
