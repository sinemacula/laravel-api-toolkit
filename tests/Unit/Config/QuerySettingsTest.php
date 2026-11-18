<?php

declare(strict_types = 1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Config\QuerySettings;
use Tests\Concerns\ReadsSettings;
use Tests\TestCase;

/**
 * Tests for the typed query bound settings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySettings::class)]
final class QuerySettingsTest extends TestCase
{
    use ReadsSettings;

    /**
     * Return the settings class under test.
     *
     * @return class-string
     */
    #[\Override]
    protected static function settings(): string
    {
        return QuerySettings::class;
    }
}
