<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Topoff\Messenger\Filament\Resources\MessageResource;

class EditMessage extends EditRecord
{
    protected static string $resource = MessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
