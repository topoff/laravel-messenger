<?php

declare(strict_types=1);

namespace Topoff\Messenger\Filament\Resources\MessageTypeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Topoff\Messenger\Filament\Resources\MessageTypeResource;

class CreateMessageType extends CreateRecord
{
    protected static string $resource = MessageTypeResource::class;
}
