<?php

namespace Topoff\Messenger\Repositories;

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Topoff\Messenger\Models\MessageType;

class MessageTypeRepository
{
    public const string CACHE_VERSION_KEY = 'messenger:message-type-repository:version';

    /**
     * Get the MessageType ID by a type
     */
    public function getIdFromTypeAndCustomer(string $type): int
    {
        $messageTypeClass = config('messenger.models.message_type');

        return $this->remember(
            static::class.':'.__FUNCTION__.':'.$type,
            fn () => $messageTypeClass::where('notification_class', $type)->select('id')->first()->id
        );
    }

    /**
     * Get the MessageType by a type
     */
    public function getFromTypeAndCustomer(string $type): MessageType
    {
        $messageTypeClass = config('messenger.models.message_type');

        return $this->remember(
            static::class.':'.__FUNCTION__.':'.$type,
            fn () => $messageTypeClass::where('notification_class', $type)->first()
        );
    }

    /**
     * Get the MessageType by ID
     */
    public function getFromId(int $id): MessageType
    {
        $messageTypeClass = config('messenger.models.message_type');

        return $this->remember(
            static::class.':'.__FUNCTION__.':'.$id,
            fn () => $messageTypeClass::where('id', $id)->first()
        );
    }

    protected function remember(string $key, \Closure $callback): mixed
    {
        $ttl = config('messenger.cache.ttl');
        $prefixedKey = $this->cacheKey($key);
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            return Cache::tags(config('messenger.cache.tag'))->remember($prefixedKey, $ttl, $callback);
        }

        return Cache::remember($prefixedKey, $ttl, $callback);
    }

    protected function cacheKey(string $key): string
    {
        return 'messenger:message-types:v'.$this->cacheVersion().':'.$key;
    }

    protected function cacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }
}
