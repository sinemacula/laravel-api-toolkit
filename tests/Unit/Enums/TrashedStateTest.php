<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\TrashedState;

/**
 * Tests for the TrashedState enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TrashedState::class)]
final class TrashedStateTest extends TestCase
{
    /**
     * Test that exactly the expected cases exist, in declaration order.
     *
     * @return void
     */
    public function testExposesTheExpectedCases(): void
    {
        $names = array_map(
            static fn (TrashedState $case): string => $case->name,
            TrashedState::cases(),
        );

        self::assertSame(['NONE', 'WITH', 'ONLY'], $names);
    }

    /**
     * Provide raw parameter values mapped to their expected state.
     *
     * @return iterable<string, array{string|null, \SineMacula\ApiToolkit\Enums\TrashedState}>
     */
    public static function parameterProvider(): iterable
    {
        yield 'with maps to With' => ['with', TrashedState::WITH];
        yield 'only maps to Only' => ['only', TrashedState::ONLY];
        yield 'null maps to None' => [null, TrashedState::NONE];
        yield 'empty string maps to None' => ['', TrashedState::NONE];
        yield 'unknown maps to None' => ['nonsense', TrashedState::NONE];
        yield 'mixed case with maps to With' => ['WiTh', TrashedState::WITH];
        yield 'upper only maps to Only' => ['ONLY', TrashedState::ONLY];
        yield 'padded with maps to With' => ['  with  ', TrashedState::WITH];
    }

    /**
     * Test that fromParameter maps raw values onto the correct state.
     *
     * @param  string|null  $value
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $expected
     * @return void
     */
    #[DataProvider('parameterProvider')]
    public function testFromParameterMapsValues(?string $value, TrashedState $expected): void
    {
        self::assertSame($expected, TrashedState::fromParameter($value));
    }
}
