<?php

declare(strict_types=1);

namespace Tigusigalpa\Birdeye\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Tigusigalpa\Birdeye\Client;

/**
 * Resolves the shared Client singleton. Because Client exposes its
 * services as typed public readonly properties (not methods) for IDE
 * support, this facade is mainly useful for `Birdeye::getFacadeRoot()`
 * (e.g. in `artisan tinker`) — prefer constructor-injecting `Client`
 * everywhere else.
 *
 * @see \Tigusigalpa\Birdeye\Client
 */
class Birdeye extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
