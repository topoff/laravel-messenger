<?php

namespace Topoff\Messenger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Topoff\Messenger\Messenger
 */
class Messenger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Topoff\Messenger\Messenger::class;
    }
}
