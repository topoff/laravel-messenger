<?php

namespace Topoff\MailManager\Nova\Resources;

use Illuminate\Support\Str;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Topoff\MailManager\Models\MessageType as MessageTypeModel;
use Topoff\MailManager\Nova\Actions\OpenSesSnsSiteAction;
use Topoff\MailManager\Nova\Actions\PreviewMessageTypeInBrowserAction;
use Topoff\MailManager\Nova\Lenses\MessagesByTypeTrackingLens;

class MessageType extends Resource
{
    public static $model = MessageTypeModel::class;

    public static $title = 'id';

    public static $group = 'Mail';

    public static $globallySearchable = false;

    public static $search = [
        'id',
        'notification_class',
    ];

    public static function label(): string
    {
        return 'Message Types';
    }

    public static function singularLabel(): string
    {
        return 'Message Type';
    }

    public function title(): string
    {
        return $this->id.' '.$this->notification_class;
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Channel', 'channel')->sortable(),
            Text::make('Notification Class', 'notification_class')->sortable()->rules('required'),
            Text::make('Single Handler', 'single_handler')->nullable()->sortable(),
            Text::make('Bulk Handler', 'bulk_handler')->nullable()->sortable(),
            Boolean::make('Direct', 'direct')->sortable(),
            Boolean::make('Dev BCC', 'dev_bcc')->sortable(),
            Number::make('Error Stop Send Minutes', 'error_stop_send_minutes')->sortable(),
            Number::make('Max Retry Attempts', 'max_retry_attempts')->sortable(),
            Boolean::make('Required Sender', 'required_sender')->sortable(),
            Boolean::make('Required Messagable', 'required_messagable')->sortable(),
            Boolean::make('Required Company Id', 'required_company_id')->sortable(),
            Boolean::make('Required Scheduled', 'required_scheduled')->sortable(),
            Boolean::make('Required Text', 'required_text')->sortable(),
            Boolean::make('Required Params', 'required_params')->sortable(),
            Text::make('Bulk Message Line', 'bulk_message_line')
                ->displayUsing(fn (?string $text): string => Str::limit((string) $text, 120))
                ->hideFromIndex(),
            Text::make('SES Configuration Set', 'ses_configuration_set')
                ->nullable()
                ->sortable()
                ->help('Config key (e.g. "default", "transactional", "marketing"). Leave empty to use identity default.')
                ->hideFromIndex(),
            DateTime::make('Created At', 'created_at')->sortable()->hideFromIndex(),
            DateTime::make('Updated At', 'updated_at')->nullable()->hideFromIndex(),
            DateTime::make('Deleted At', 'deleted_at')->nullable()->hideFromIndex(),

            HasMany::make('Messages', 'messages', Message::class),
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
        return [
            new MessagesByTypeTrackingLens,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            (new PreviewMessageTypeInBrowserAction)->sole()->confirmText('')->confirmButtonText('Preview'),
            (new OpenSesSnsSiteAction)->standalone()->confirmText('Open the SES/SNS dashboard in a new tab?')->confirmButtonText('Open'),
        ];
    }
}
