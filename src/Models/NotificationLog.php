<?php

namespace Topoff\Messenger\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = (string) config('messenger.logs.notification_log_table', 'notification_log');

        $connection = config('messenger.logs.connection');
        if (is_string($connection) && $connection !== '') {
            $this->connection = $connection;
        }
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'channel' => 'string',
            'notifyable_id' => 'string',
            'to' => 'string',
            'type' => 'string',
            'notification_id' => 'string',
            'created_at' => 'datetime',
        ];
    }
}
