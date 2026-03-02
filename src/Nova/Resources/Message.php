<?php

namespace Topoff\MailManager\Nova\Resources;

use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Topoff\MailManager\Models\Message as MessageModel;
use Topoff\MailManager\Nova\Actions\PreviewMessageInBrowserAction;
use Topoff\MailManager\Nova\Actions\ResendAsNewMessageAction;
use Topoff\MailManager\Nova\Actions\ShowRealSentMessageAction;
use Topoff\MailManager\Nova\Filters\DateFilter;
use Topoff\MailManager\Nova\Filters\MessagesMessageableTypeFilter;
use Topoff\MailManager\Nova\Filters\MessagesMessageTypeFilter;
use Topoff\MailManager\Nova\Filters\MessagesReceiverTypeFilter;
use Topoff\MailManager\Nova\Filters\MessagesStatusFilter;
use Topoff\MailManager\Nova\Lenses\MessagesByTypeTrackingLens;
use Topoff\MailManager\Nova\Lenses\MessagesTrackingLens;

class Message extends Resource
{
    public static $model = MessageModel::class;

    public static $title = 'id';

    public static $group = 'Mail';

    public static $globallySearchable = false;

    public static $search = [
        'id',
        'tracking_subject',
        'tracking_sender_email',
        'tracking_sender_name',
        'tracking_recipient_email',
        'tracking_recipient_name',
        'tracking_message_id',
        'tracking_hash',
    ];

    public static function label(): string
    {
        return 'Messages';
    }

    public static function singularLabel(): string
    {
        return 'Message';
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Receiver Type', 'receiver_type')->hideFromIndex(),
            Number::make('Receiver Id', 'receiver_id')->hideFromIndex(),
            Text::make('Sender Type', 'sender_type')->hideFromIndex(),
            Number::make('Sender Id', 'sender_id')->hideFromIndex(),
            Number::make('Company Id', 'company_id')->sortable(),
            Number::make('Message Type Id', 'message_type_id')->sortable(),
            Text::make('Messagable Type', 'messagable_type')->hideFromIndex(),
            Number::make('Messagable Id', 'messagable_id')->hideFromIndex(),
            KeyValue::make('Params', 'params')->nullable()->hideFromIndex(),
            Textarea::make('Text', 'text')->alwaysShow()->hideFromIndex(),
            Text::make('Locale', 'locale')->sortable(),
            DateTime::make('Scheduled At', 'scheduled_at')->nullable()->sortable(),
            DateTime::make('Reserved At', 'reserved_at')->nullable()->sortable(),
            DateTime::make('Error At', 'error_at')->nullable()->sortable(),
            DateTime::make('Sent At', 'sent_at')->nullable()->sortable(),
            Number::make('Attempts', 'attempts')->sortable(),
            Number::make('Email Error Code', 'email_error_code')->nullable()->hideFromIndex(),
            Text::make('Email Error', 'email_error')->nullable()->hideFromIndex(),
            Text::make('Tracking Subject', 'tracking_subject')->sortable(),
            Text::make('Sender', 'tracking_sender_email')->sortable(),
            Text::make('Recipient', 'tracking_recipient_email')->sortable(),
            Number::make('Opens', 'tracking_opens')->sortable(),
            Number::make('Clicks', 'tracking_clicks')->sortable(),
            DateTime::make('Opened At', 'tracking_opened_at')->nullable()->hideFromIndex(),
            DateTime::make('Clicked At', 'tracking_clicked_at')->nullable()->hideFromIndex(),
            Text::make('Tracking Message Id', 'tracking_message_id')->hideFromIndex(),
            Text::make('Tracking Hash', 'tracking_hash')->hideFromIndex(),
            Text::make('Sender Name', 'tracking_sender_name')->hideFromIndex(),
            Text::make('Recipient Name', 'tracking_recipient_name')->hideFromIndex(),
            Text::make('Tracking Content Path', 'tracking_content_path')->hideFromIndex(),
            KeyValue::make('Tracking Meta', 'tracking_meta')->nullable()->hideFromIndex(),
            DateTime::make('Created At', 'created_at')->sortable()->hideFromIndex(),
            DateTime::make('Updated At', 'updated_at')->nullable()->hideFromIndex(),
            DateTime::make('Deleted At', 'deleted_at')->nullable()->hideFromIndex(),
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
        return [
            new DateFilter("created_at", 'today'),
            new DateFilter("sent_at", null),
            new DateFilter("error_at", null),
            new MessagesStatusFilter,
            new MessagesReceiverTypeFilter,
            new MessagesMessageTypeFilter,
            new MessagesMessageableTypeFilter,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function lenses(NovaRequest $request): array
    {
        return [
            new MessagesByTypeTrackingLens,
            new MessagesTrackingLens,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            (new ShowRealSentMessageAction)->confirmText('')->confirmButtonText('Go'),
            (new ResendAsNewMessageAction)->confirmText('')->confirmButtonText('Go'),
            (new PreviewMessageInBrowserAction)->confirmText('')->confirmButtonText('Go'),
        ];
    }
}
