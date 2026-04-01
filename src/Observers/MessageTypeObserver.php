<?php

namespace Topoff\Messenger\Observers;

use Illuminate\Cache\TaggableStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Topoff\Messenger\Repositories\MessageTypeRepository;

class MessageTypeObserver
{
    /**
     * Handle the MessageType "created" event.
     */
    public function created(Model $messageType): void
    {
        $this->clearCachedMessageTypes();
    }

    /**
     * Handle the MessageType "updated" event.
     */
    public function updated(Model $messageType): void
    {
        $this->clearCachedMessageTypes();
    }

    /**
     * Handle the MessageType "deleted" event.
     */
    public function deleted(Model $messageType): void
    {
        $this->clearCachedMessageTypes();
    }

    /**
     * Handle the MessageType "restored" event.
     */
    public function restored(Model $messageType): void
    {
        $this->clearCachedMessageTypes();
    }

    /**
     * Handle the MessageType "force deleted" event.
     */
    public function forceDeleted(Model $messageType): void
    {
        $this->clearCachedMessageTypes();
    }

    /**
     * Removes all Cache entries with the MessageType Tag.
     */
    private function clearCachedMessageTypes(): void
    {
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            Cache::tags([config('messenger.cache.tag')])->flush();
        } else {
            Cache::forever(MessageTypeRepository::CACHE_VERSION_KEY, ((int) Cache::get(MessageTypeRepository::CACHE_VERSION_KEY, 1)) + 1);
        }
    }
}
