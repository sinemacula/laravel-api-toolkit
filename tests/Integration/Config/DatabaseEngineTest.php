<?php

declare(strict_types = 1);

namespace Tests\Integration\Config;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Tests that the suite runs against the engine DB_DRIVER names.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
#[Group('mysql')]
#[Group('pgsql')]
final class DatabaseEngineTest extends TestCase
{
    /**
     * Test that the default connection uses the driver DB_DRIVER names.
     *
     * @return void
     */
    public function testDefaultConnectionUsesTheNamedDriver(): void
    {
        self::assertSame(env('DB_DRIVER', 'sqlite'), DB::connection()->getDriverName());
    }
}
