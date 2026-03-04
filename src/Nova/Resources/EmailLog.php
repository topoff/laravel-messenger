<?php

namespace Topoff\Messenger\Nova\Resources;

use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Topoff\Messenger\Models\EmailLog as EmailLogModel;

class EmailLog extends Resource
{
    public static $model = EmailLogModel::class;

    public static $title = 'id';

    public static $group = 'Mail';

    public static $search = ['id', 'to', 'subject'];

    public static function label(): string
    {
        return 'Email Logs';
    }

    public static function singularLabel(): string
    {
        return 'Email Log';
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('To', 'to')->sortable(),
            Text::make('Cc', 'cc')->sortable()->nullable(),
            Text::make('Bcc', 'bcc')->sortable()->nullable(),
            Text::make('Subject', 'subject')->sortable(),
            Boolean::make('Has Attachment', 'has_attachment')->sortable(),
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
