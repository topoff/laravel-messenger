<?php

namespace Topoff\Messenger\Filament\Resources\MessageTypeResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\URL;
use Topoff\Messenger\Filament\Resources\MessageTypeResource;

class ListMessageTypes extends ListRecords
{
    protected static string $resource = MessageTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ses_sns_dashboard')
                ->label('SES/SNS Dashboard')
                ->icon('heroicon-o-cloud')
                ->color('gray')
                ->url(fn () => URL::temporarySignedRoute(
                    'messenger.ses-sns.dashboard',
                    now()->addMinutes(30)
                ))
                ->openUrlInNewTab(),
            Actions\CreateAction::make(),
        ];
    }
}
