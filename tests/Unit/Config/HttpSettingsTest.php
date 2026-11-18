<?php

declare(strict_types = 1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Config\HttpSettings;
use Tests\Concerns\ReadsSettings;
use Tests\TestCase;

/**
 * Tests for the typed request, response and middleware settings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HttpSettings::class)]
final class HttpSettingsTest extends TestCase
{
    use ReadsSettings;

    /**
     * Test that the debug info setting reads null as following app.debug.
     *
     * @return void
     */
    public function testDebugInfoReadsNullAsFollowingTheApplication(): void
    {
        Config::set('api-toolkit.exceptions.include_debug_info', null);

        self::assertNull(HttpSettings::shouldIncludeDebugInfo());

        Config::set('api-toolkit.exceptions.include_debug_info', 'yes');

        self::assertTrue(HttpSettings::shouldIncludeDebugInfo());
    }

    /**
     * Return the settings class under test.
     *
     * @return class-string
     */
    #[\Override]
    protected static function settings(): string
    {
        return HttpSettings::class;
    }
}
