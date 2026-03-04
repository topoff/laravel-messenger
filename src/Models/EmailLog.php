<?php

namespace Topoff\Messenger\Models;

use Illuminate\Database\Eloquent\Model;
use Topoff\Messenger\Models\Traits\DateScopesTrait;

class EmailLog extends Model
{
    use DateScopesTrait;

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = (string) config('messenger.logs.email_log_table', 'email_log');

        $connection = config('messenger.logs.connection');
        if (is_string($connection) && $connection !== '') {
            $this->connection = $connection;
        }
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
