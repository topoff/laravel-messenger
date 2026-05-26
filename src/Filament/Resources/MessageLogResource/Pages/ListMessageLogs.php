<?php

declare(strict_types=1);

namespace Topoff\Messenger\Filament\Resources\MessageLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Topoff\Messenger\Filament\Resources\MessageLogResource;

class ListMessageLogs extends ListRecords
{
    protected static string $resource = MessageLogResource::class;
}
