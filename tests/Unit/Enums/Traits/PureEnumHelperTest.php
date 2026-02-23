<?php

namespace Tests\Unit\Enums\Traits;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\Traits\PureEnumHelper;
use Tests\Fixtures\Enums\PureState;

/**
 * Tests for the PureEnumHelper trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PureEnumHelper::class)]
class PureEnumHelperTest extends TestCase
{
    /**
     * Test that tryFrom with exact case name returns the matching case.
     *
     * @return void
     */
    public function testTryFromWithExactCaseNameReturnsCase(): void
    {
        $result = PureState::tryFrom('PENDING');

        static::assertSame(PureState::PENDING, $result);
    }

    /**
     * Test that tryFrom is case-insensitive.
     *
     * @param  string  $input
     * @param  \Tests\Fixtures\Enums\PureState  $expectedCase
     * @return void
     */
    #[DataProvider('caseInsensitiveProvider')]
    public function testTryFromIsCaseInsensitive(string $input, PureState $expectedCase): void
    {
        $result = PureState::tryFrom($input);

        static::assertSame($expectedCase, $result);
    }

    /**
     * Test that tryFrom with an invalid string returns null.
     *
     * @param  string  $input
     * @return void
     */
    #[DataProvider('invalidStringProvider')]
    public function testTryFromWithInvalidStringReturnsNull(string $input): void
    {
        $result = PureState::tryFrom($input);

        static::assertNull($result);
    }

    /**
     * Test that tryFrom with non-string values returns null.
     *
     * @param  mixed  $input
     * @return void
     */
    #[DataProvider('nonStringProvider')]
    public function testTryFromWithNonStringValuesReturnsNull(mixed $input): void
    {
        static::assertFalse(is_string($input), 'Input must be a non-string type');
        static::assertNull(PureState::tryFrom($input));
    }

    /**
     * Test that tryFrom matches all defined cases.
     *
     * @param  string  $name
     * @param  \Tests\Fixtures\Enums\PureState  $expectedCase
     * @return void
     */
    #[DataProvider('allCasesProvider')]
    public function testTryFromMatchesAllDefinedCases(string $name, PureState $expectedCase): void
    {
        $result = PureState::tryFrom($name);

        static::assertSame($expectedCase, $result);
    }

    /**
     * Provide case-insensitive inputs and their expected results.
     *
     * @return iterable<string, array{string, \Tests\Fixtures\Enums\PureState}>
     */
    public static function caseInsensitiveProvider(): iterable
    {
        yield 'lowercase' => ['pending', PureState::PENDING];
        yield 'mixed case' => ['Approved', PureState::APPROVED];
        yield 'all caps' => ['REJECTED', PureState::REJECTED];
        yield 'random casing' => ['rEjEcTeD', PureState::REJECTED];
    }

    /**
     * Provide invalid string inputs that should return null.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidStringProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'nonexistent case' => ['INVALID'];
        yield 'partial match' => ['PEND'];
        yield 'extra whitespace' => [' PENDING '];
    }

    /**
     * Provide non-string values that should return null.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringProvider(): iterable
    {
        yield 'integer' => [123];
        yield 'float' => [1.5];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'null' => [null];
        yield 'array' => [[]];
    }

    /**
     * Provide all defined PureState cases.
     *
     * @return iterable<string, array{string, \Tests\Fixtures\Enums\PureState}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'PENDING' => ['PENDING', PureState::PENDING];
        yield 'APPROVED' => ['APPROVED', PureState::APPROVED];
        yield 'REJECTED' => ['REJECTED', PureState::REJECTED];
    }
}
