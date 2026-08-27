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

        return $this->hydrate($this->remember(
            static::class.':'.__FUNCTION__.':'.$type,
            fn () => $messageTypeClass::where('notification_class', $type)->first()?->getAttributes()
        ));
    }

    /**
     * Get the MessageType by ID
     */
    public function getFromId(int $id): MessageType
    {
        $messageTypeClass = config('messenger.models.message_type');

        return $this->hydrate($this->remember(
            static::class.':'.__FUNCTION__.':'.$id,
            fn () => $messageTypeClass::where('id', $id)->first()?->getAttributes()
        ));
    }

    /**
     * Only scalar attribute arrays are cached — serialized Eloquent objects
     * come back as __PHP_Incomplete_Class from a shared (e.g. database)
     * cache store when another process reads them.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function hydrate(array $attributes): MessageType
    {
        $messageTypeClass = config('messenger.models.message_type');

        /** @var MessageType $model */
        $model = (new $messageTypeClass)->newFromBuilder($attributes);

        return $model;
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
        // `f2` = cache format 2 (attribute arrays) — entries written by
        // older releases hold serialized objects and must never be read
        // by this code path; they simply expire.
        return 'messenger:message-types:f2:v'.$this->cacheVersion().':'.$key;
    }

    protected function cacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }
}
