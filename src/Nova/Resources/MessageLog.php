<?php

namespace Topoff\Messenger\Nova\Resources;

use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Topoff\Messenger\Models\MessageLog as MessageLogModel;

class MessageLog extends Resource
{
    public static $model = MessageLogModel::class;

    public static $title = 'id';

    public static $group = 'Mail';

    public static $search = ['id', 'channel', 'to', 'subject', 'type'];

    public static function label(): string
    {
        return 'Message Logs';
    }

    public static function singularLabel(): string
    {
        return 'Message Log';
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Channel', 'channel')->sortable(),
            Text::make('To', 'to')->sortable(),
            Text::make('Type', 'type')->sortable()->nullable(),
            Text::make('Subject', 'subject')->sortable()->nullable(),
            Text::make('Cc', 'cc')->sortable()->nullable(),
            Text::make('Bcc', 'bcc')->sortable()->nullable(),
            Boolean::make('Has Attachment', 'has_attachment')->sortable(),
            Text::make('Notifyable Id', 'notifyable_id')->sortable()->nullable(),
            Text::make('Notification Id', 'notification_id')->sortable()->nullable(),
            DateTime::make('Created At', 'created_at')->sortable()->hideWhenCreating()->hideWhenUpdating(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}
