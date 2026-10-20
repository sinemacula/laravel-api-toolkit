<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Foundation\Application;

/**
 * A cache store whose every operation throws, as a store behind an unreachable
 * network would.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UnreachableCacheStore
{
    /** @var string The message every store operation fails with. */
    public const string MESSAGE = 'The cache store is unreachable.';

    /**
     * Make the unreachable store the default cache store of the application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public static function install(Application $app): void
    {
        $store = \Mockery::mock(Store::class);

        $store->shouldReceive('getPrefix')->andReturn('');
        $store->shouldReceive('get', 'many', 'put', 'putMany', 'increment', 'decrement', 'forever', 'touch', 'forget', 'flush')
            ->andThrow(new \RuntimeException(self::MESSAGE));

        /** @var \Illuminate\Cache\CacheManager $cache */
        $cache = $app->make('cache');

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $cache->extend('unreachable', fn (): Repository => new Repository($store));

        $config->set('cache.stores.unreachable', ['driver' => 'unreachable']);
        $config->set('cache.default', 'unreachable');
    }
}
