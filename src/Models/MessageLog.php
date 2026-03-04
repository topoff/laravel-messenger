<?php

namespace Topoff\Messenger\Models;

use Illuminate\Database\Eloquent\Model;
use Topoff\Messenger\Models\Traits\DateScopesTrait;

class MessageLog extends Model
{
    use DateScopesTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = (string) config('messenger.logs.message_log_table', 'message_log');

        $connection = config('messenger.logs.connection');
        if (is_string($connection) && $connection !== '') {
            $this->connection = $connection;
        }
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'has_attachment' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
