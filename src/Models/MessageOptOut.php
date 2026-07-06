<?php

namespace Topoff\Messenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Opt-out of a receiver for a consent scope (v9, E79). See migration
 * 0019 for the scope grammar. Managed through ConsentService.
 *
 * @property int $id
 * @property string $receiver_type
 * @property string $receiver_id
 * @property string $scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MessageOptOut extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if ($connection = config('messenger.database.connection')) {
            $this->connection = $connection;
        }
    }
}
