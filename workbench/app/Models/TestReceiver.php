<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Topoff\Messenger\Contracts\MessageReceiverInterface;

class TestReceiver extends Model implements MessageReceiverInterface
{
    protected $guarded = [];

    public $timestamps = false;

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMyVipanyUri(): string
    {
        return '/receiver/'.$this->id;
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
