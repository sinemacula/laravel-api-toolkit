<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AudiencePosture;

/**
 * Tests for the AudiencePosture enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AudiencePosture::class)]
final class AudiencePostureTest extends TestCase
{
    /**
     * Test that each case is backed by the value the config file names.
     *
     * @return void
     */
    public function testCasesAreBackedByTheirConfigValues(): void
    {
        $cases = array_map(static fn (AudiencePosture $case): array => [$case->name, $case->value], AudiencePosture::cases());

        self::assertSame([['ALLOWLIST', 'allowlist'], ['BLOCKLIST', 'blocklist']], $cases);
    }
}
