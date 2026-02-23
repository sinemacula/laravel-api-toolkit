<?php

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;

/**
 * Tests for the FieldOrderingStrategy enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldOrderingStrategy::class)]
class FieldOrderingStrategyTest extends TestCase
{
    /**
     * Test that each case has the expected string value.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy  $case
     * @param  string  $expectedValue
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testCaseHasExpectedValue(FieldOrderingStrategy $case, string $expectedValue): void
    {
        static::assertSame($expectedValue, $case->value);
    }

    /**
     * Test that DEFAULT has value 'default'.
     *
     * @return void
     */
    public function testDefaultCaseValue(): void
    {
        static::assertSame('default', FieldOrderingStrategy::DEFAULT->value);
    }

    /**
     * Test that BY_REQUESTED_FIELDS has value 'by_requested_fields'.
     *
     * @return void
     */
    public function testByRequestedFieldsCaseValue(): void
    {
        static::assertSame('by_requested_fields', FieldOrderingStrategy::BY_REQUESTED_FIELDS->value);
    }

    /**
     * Test that exactly two cases exist.
     *
     * @return void
     */
    public function testExpectedCaseCount(): void
    {
        static::assertCount(2, FieldOrderingStrategy::cases());
    }

    /**
     * Provide all FieldOrderingStrategy cases with their expected values.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\FieldOrderingStrategy, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'DEFAULT' => [FieldOrderingStrategy::DEFAULT, 'default'];
        yield 'BY_REQUESTED_FIELDS' => [FieldOrderingStrategy::BY_REQUESTED_FIELDS, 'by_requested_fields'];
    }
}
