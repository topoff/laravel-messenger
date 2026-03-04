<?php

namespace Topoff\MailManager\Repositories;

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Topoff\MailManager\Models\MessageType;

class MessageTypeRepository
{
    public const string CACHE_VERSION_KEY = 'mail-manager:message-type-repository:version';

    /**
     * Get the MessageType ID by a type
     */
    public function getIdFromTypeAndCustomer(string $type): int
    {
        $messageTypeClass = config('mail-manager.models.message_type');

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
        $messageTypeClass = config('mail-manager.models.message_type');

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
        $messageTypeClass = config('mail-manager.models.message_type');

        return $this->remember(
            static::class.':'.__FUNCTION__.':'.$id,
            fn () => $messageTypeClass::where('id', $id)->first()
        );
    }

    protected function remember(string $key, \Closure $callback): mixed
    {
        $ttl = config('mail-manager.cache.ttl');
        $prefixedKey = $this->cacheKey($key);
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            return Cache::tags(config('mail-manager.cache.tag'))->remember($prefixedKey, $ttl, $callback);
        }

        return Cache::remember($prefixedKey, $ttl, $callback);
    }

    protected function cacheKey(string $key): string
    {
        return 'mail-manager:message-types:v'.$this->cacheVersion().':'.$key;
    }

    protected function cacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }
}
