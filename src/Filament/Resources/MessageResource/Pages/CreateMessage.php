<?php

namespace Topoff\Messenger\Filament\Resources\MessageResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Topoff\Messenger\Filament\Resources\MessageResource;

class CreateMessage extends CreateRecord
{
    protected static string $resource = MessageResource::class;
}
