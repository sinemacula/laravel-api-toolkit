<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\MiddlewareScope;

/**
 * Tests for the MiddlewareScope enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MiddlewareScope::class)]
final class MiddlewareScopeTest extends TestCase
{
    /**
     * Test that each case is backed by the value the config file names.
     *
     * @return void
     */
    public function testCasesAreBackedByTheirConfigValues(): void
    {
        $cases = array_map(static fn (MiddlewareScope $case): array => [$case->name, $case->value], MiddlewareScope::cases());

        self::assertSame([['GLOBAL', 'global'], ['API', 'api']], $cases);
    }
}
