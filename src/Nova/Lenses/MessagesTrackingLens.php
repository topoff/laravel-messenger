<?php

namespace Topoff\MailManager\Nova\Lenses;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Lenses\Lens;

class MessagesTrackingLens extends Lens
{
    /**
     * Get the query builder / paginator for the lens.
     */
    public static function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering(
            $request->withFilters($query),
            fn (Builder $query): Builder => $query->orderByDesc('id'),
        );
    }

    /**
     * Get the fields available to the lens.
     *
     * @return array<int, mixed>
     */
    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Number::make('Message Type Id', 'message_type_id')->sortable(),
            Text::make('Subject', 'tracking_subject')->sortable(),
            Text::make('Sender', 'tracking_sender_contact')->sortable(),
            Text::make('Recipient', 'tracking_recipient_contact')->sortable(),
            Number::make('Opens', 'tracking_opens')->sortable(),
            Number::make('Clicks', 'tracking_clicks')->sortable(),
            DateTime::make('Opened At', 'tracking_opened_at')->sortable(),
            DateTime::make('Clicked At', 'tracking_clicked_at')->sortable(),
            DateTime::make('Sent At', 'sent_at')->sortable(),
            Text::make('Message Id', 'tracking_message_id'),
            Text::make('Hash', 'tracking_hash'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function filters(Request $request): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function actions(Request $request): array
    {
        return [];
    }

    public function name(): string
    {
        return 'Tracking Details';
    }
}
