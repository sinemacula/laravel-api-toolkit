<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\RenderStrategy;

/**
 * Tests for the RenderStrategy enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RenderStrategy::class)]
final class RenderStrategyTest extends TestCase
{
    /**
     * Test that each case is backed by the value the config file names.
     *
     * @return void
     */
    public function testCasesAreBackedByTheirConfigValues(): void
    {
        $cases = array_map(static fn (RenderStrategy $case): array => [$case->name, $case->value], RenderStrategy::cases());

        self::assertSame([['ALWAYS_JSON', 'always_json'], ['JSON_WHEN_EXPECTED', 'json_when_expected'], ['AUTO', 'auto']], $cases);
    }
}
