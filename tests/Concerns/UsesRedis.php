<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Redis;

/**
 * Runs a test against a real redis server.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait UsesRedis
{
    /**
     * Skip the test where no redis server answers, unless REDIS_HOST names one,
     * so a leg that provides redis fails rather than skips when it is missing.
     *
     * @return void
     */
    protected function requireRedis(): void
    {
        if (env('REDIS_HOST') !== null) {
            return;
        }

        try {
            Redis::connection()->command('ping');
        } catch (\Throwable) {
            self::markTestSkipped('No redis server answered; set REDIS_HOST to require one.');
        }
    }
}
