<?php

namespace Topoff\Messenger\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $channel
 * @property string $notification_class
 * @property string|null $single_handler
 * @property string|null $bulk_handler
 * @property bool $direct
 * @property bool $dev_bcc
 * @property int $error_stop_send_minutes
 * @property bool $required_sender
 * @property bool $required_messagable
 * @property bool $required_company_id
 * @property bool $required_scheduled
 * @property bool $required_text
 * @property bool $required_params
 * @property string|null $bulk_message_line
 * @property string|null $ses_configuration_set
 * @property int $max_retry_attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class MessageType extends Model
{
    use SoftDeletes;

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
     * Scope a query to only include direct MessageTypes
     */
    public function scopeDirect(Builder $query): Builder
    {
        return $query->where('direct', true);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(config('messenger.models.message'), 'message_type_id');
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'dev_bcc' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
