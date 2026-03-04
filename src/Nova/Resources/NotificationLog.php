<?php

namespace Topoff\Messenger\Nova\Resources;

use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Topoff\Messenger\Models\NotificationLog as NotificationLogModel;

class NotificationLog extends Resource
{
    public static $model = NotificationLogModel::class;

    public static $title = 'id';

    public static $group = 'Mail';

    public static $search = ['id', 'channel', 'notifyable_id', 'to', 'type', 'notification_id'];

    public static function label(): string
    {
        return 'Notification Logs';
    }

    public static function singularLabel(): string
    {
        return 'Notification Log';
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Channel', 'channel')->sortable(),
            Text::make('Notifyable Id', 'notifyable_id')->sortable(),
            Text::make('To', 'to')->sortable(),
            Text::make('Type', 'type')->sortable(),
            Text::make('Notification Id', 'notification_id')->sortable(),
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
