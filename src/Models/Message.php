<?php

namespace Topoff\Messenger\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Topoff\Messenger\Contracts\MessageReceiverInterface;
use Topoff\Messenger\Models\Traits\DateScopesTrait;

/**
 * @property int $id
 * @property string|null $receiver_type
 * @property int|null $receiver_id
 * @property string|null $sender_type
 * @property int|null $sender_id
 * @property int|null $company_id
 * @property int $message_type_id
 * @property string|null $messagable_type
 * @property int|null $messagable_id
 * @property array|null $params
 * @property string|null $locale
 * @property int|null $attempts
 * @property string $channel
 * @property int|null $error_code
 * @property string|null $error_message
 * @property string|null $tracking_hash
 * @property string|null $tracking_message_id
 * @property string|null $tracking_sender_name
 * @property string|null $tracking_sender_contact
 * @property string|null $tracking_recipient_name
 * @property string|null $tracking_recipient_contact
 * @property string|null $tracking_subject
 * @property int $tracking_opens
 * @property int $tracking_clicks
 * @property array|null $tracking_meta
 * @property string|null $tracking_content
 * @property string|null $tracking_content_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $reserved_at
 * @property Carbon|null $error_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $bounced_at
 * @property Carbon|null $tracking_opened_at
 * @property Carbon|null $tracking_clicked_at
 * @property-read MessageType $messageType
 * @property-read MessageReceiverInterface|Model|null $receiver
 */
class Message extends Model
{
    use DateScopesTrait, SoftDeletes;

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if ($connection = config('messenger.database.connection')) {
            $this->connection = $connection;
        }
    }

    /**
     * Only MessageTypes with @see \Topoff\Messenger\Models\MessageType::scopeDirect()
     */
    public function directMessageTypes()
    {
        return $this->messageType()->direct();
    }

    public function messagable(): MorphTo
    {
        $messagableType = $this->getAttribute('messagable_type');
        if (is_string($messagableType) && $messagableType !== '' && ! class_exists($messagableType)) {
            $this->setAttribute('messagable_type', null);
            $this->setAttribute('messagable_id', null);
        }

        return $this->morphTo();
    }

    public function messageType(): BelongsTo
    {
        return $this->belongsTo(config('messenger.models.message_type'));
    }

    /**
     * @return MorphTo|Model|MessageReceiverInterface
     */
    public function receiver(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'receiver_type', 'receiver_id');
    }

    public function sender(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sender_type', 'sender_id');
    }

    /**
     * Only Messages with real problems which couldn't be sent
     */
    #[Scope]
    protected function hasErrorAndIsNotSent(Builder $query): Builder
    {
        return $query->whereNotNull('error_at')->whereNull('sent_at')->whereNull('failed_at');
    }

    /**
     * Only Messages with real problems which couldn't be sent
     */
    #[Scope]
    protected function isScheduledButNotSent(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_at')->whereNull('error_at')->whereNull('reserved_at');
    }

    /**
     * Get the date in the defined format according to the current locale.
     */
    protected function dateFormated(): Attribute
    {
        return Attribute::make(get: fn (): string => ($this->created_at) ? Date::make($this->created_at)->isoFormat('LL') : '');
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'message_type_id' => 'integer',
            'messagable_type' => 'string',
            'messagable_id' => 'integer',
            'params' => 'array',
            'locale' => 'string',
            'attempts' => 'integer',
            'channel' => 'string',
            'error_code' => 'integer',
            'error_message' => 'string',
            'tracking_hash' => 'string',
            'tracking_message_id' => 'string',
            'tracking_sender_name' => 'string',
            'tracking_sender_contact' => 'string',
            'tracking_recipient_name' => 'string',
            'tracking_recipient_contact' => 'string',
            'tracking_subject' => 'string',
            'tracking_opens' => 'integer',
            'tracking_clicks' => 'integer',
            'tracking_meta' => 'array',
            'tracking_content' => 'string',
            'tracking_content_path' => 'string',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'reserved_at' => 'datetime',
            'error_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'bounced_at' => 'datetime',
            'tracking_opened_at' => 'datetime',
            'tracking_clicked_at' => 'datetime',
        ];
    }
}
