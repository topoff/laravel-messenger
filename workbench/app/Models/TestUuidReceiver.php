<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Topoff\Messenger\Contracts\MessageReceiverInterface;

/**
 * Fixture model with a UUID primary key, mirroring host applications (e.g.
 * Kibora) whose receiver / messagable models use string UUID keys.
 */
class TestUuidReceiver extends Model implements MessageReceiverInterface
{
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getResourceUri(): string
    {
        return '/uuid-receiver/'.$this->id;
    }

    public function setEmailToInvalid(bool $isManualCall = true): void
    {
        $this->email_invalid_at = now();
        $this->save();
    }

    public function getEmailIsValid(): bool
    {
        return $this->email_invalid_at === null;
    }

    public function preferredLocale(): string
    {
        return $this->locale ?? 'en';
    }
}
