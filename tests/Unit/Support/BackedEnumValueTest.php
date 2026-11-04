<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Stringable;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Support\BackedEnumValue;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\NumericCode;
use Tests\TestCase;

/**
 * Tests for the BackedEnumValue coercion.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BackedEnumValue::class)]
final class BackedEnumValueTest extends TestCase
{
    /**
     * Provide values with the case coercive typing resolves them to, including
     * fractional values it truncates and the empty string, which Laravel's
     * validator never passes to the enum rule.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, mixed, \BackedEnum|null}>
     */
    public static function coercedValueProvider(): iterable
    {
        yield from self::losslessValueProvider();
        yield 'fractional numeric string truncates' => [Magnitude::class, '1.9', Magnitude::ONE];
        yield 'negative fractional numeric string truncates towards zero' => [Magnitude::class, '-3.5', Magnitude::NEGATIVE];
        yield 'fractional float truncates' => [Magnitude::class, 1.5, Magnitude::ONE];
        yield 'fractional float truncates for a string-backed enum' => [NumericCode::class, 1.5, NumericCode::ONE];
        yield 'empty string' => [Magnitude::class, '', null];
    }

    /**
     * Test that a value resolves to the case tryFrom() resolves it to under
     * coercive typing, or to no case where coercion refuses it.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @param  mixed  $value
     * @param  \BackedEnum|null  $expected
     * @return void
     */
    #[DataProvider('coercedValueProvider')]
    public function testTryFromResolvesTheCaseCoercionResolves(string $enum, mixed $value, ?\BackedEnum $expected): void
    {
        self::assertSame($expected, BackedEnumValue::tryFrom($enum, $value));
    }

    /**
     * Provide values coercion reads without losing precision, with the case
     * each resolves to.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, mixed, \BackedEnum|null}>
     */
    public static function losslessValueProvider(): iterable
    {
        yield 'integer' => [Magnitude::class, 1, Magnitude::ONE];
        yield 'integer string' => [Magnitude::class, '1', Magnitude::ONE];
        yield 'zero-padded string' => [Magnitude::class, '01', Magnitude::ONE];
        yield 'leading whitespace' => [Magnitude::class, ' 1', Magnitude::ONE];
        yield 'trailing whitespace' => [Magnitude::class, '1 ', Magnitude::ONE];
        yield 'surrounding tab and newline' => [Magnitude::class, "\t1\n", Magnitude::ONE];
        yield 'integral decimal string' => [Magnitude::class, '1.0', Magnitude::ONE];
        yield 'exponent string' => [Magnitude::class, '1e0', Magnitude::ONE];
        yield 'explicit plus sign' => [Magnitude::class, '+1', Magnitude::ONE];
        yield 'negative string' => [Magnitude::class, '-3', Magnitude::NEGATIVE];
        yield 'zero string' => [Magnitude::class, '0', Magnitude::ZERO];
        yield 'negative zero string' => [Magnitude::class, '-0', Magnitude::ZERO];
        yield 'double zero string' => [Magnitude::class, '00', Magnitude::ZERO];
        yield 'true' => [Magnitude::class, true, Magnitude::ONE];
        yield 'false' => [Magnitude::class, false, Magnitude::ZERO];
        yield 'integral float' => [Magnitude::class, 1.0, Magnitude::ONE];
        yield 'negative zero float' => [Magnitude::class, -0.0, Magnitude::ZERO];
        yield 'maximum integer string' => [Magnitude::class, '9223372036854775807', Magnitude::MAXIMUM];
        yield 'minimum integer string' => [Magnitude::class, '-9223372036854775808', Magnitude::MINIMUM];
        yield 'minimum integer' => [Magnitude::class, PHP_INT_MIN, Magnitude::MINIMUM];
        yield 'minimum integer as a float' => [Magnitude::class, (float) PHP_INT_MIN, Magnitude::MINIMUM];
        yield 'minimum integer as an exponent string' => [Magnitude::class, '-9.2233720368547758E18', Magnitude::MINIMUM];
        yield 'stringable for an int-backed enum' => [Magnitude::class, new Stringable('1'), null];
        yield 'stringable for a string-backed enum' => [NumericCode::class, new Stringable('1'), NumericCode::ONE];
        yield 'object that is not stringable' => [Magnitude::class, new \stdClass, null];
        yield 'hexadecimal string' => [Magnitude::class, '0x1', null];
        yield 'word' => [Magnitude::class, 'abc', null];
        yield 'leading digits' => [Magnitude::class, '2abc', null];
        yield 'digit separator' => [Magnitude::class, '1_0', null];
        yield 'unknown integer string' => [Magnitude::class, '2', null];
        yield 'integer string above the range' => [Magnitude::class, '9223372036854775808', null];
        yield 'infinite exponent string' => [Magnitude::class, '1e999', null];
        yield 'float above the range' => [Magnitude::class, 9.2233720368547758E18, null];
        yield 'infinite float' => [Magnitude::class, INF, null];
        yield 'not a number' => [Magnitude::class, NAN, null];
        yield 'array' => [Magnitude::class, [], null];
        yield 'string backing' => [NumericCode::class, '1', NumericCode::ONE];
        yield 'integer for a string-backed enum' => [NumericCode::class, 1, NumericCode::ONE];
        yield 'true for a string-backed enum' => [NumericCode::class, true, NumericCode::ONE];
        yield 'integral float for a string-backed enum' => [NumericCode::class, 1.0, NumericCode::ONE];
        yield 'false for a string-backed enum' => [NumericCode::class, false, NumericCode::ZERO];
        yield 'zero for a string-backed enum' => [NumericCode::class, 0, NumericCode::ZERO];
        yield 'float above the range for a string-backed enum' => [NumericCode::class, 1e20, NumericCode::HUGE];
        yield 'padded string for a string-backed enum' => [NumericCode::class, ' 1', null];
        yield 'zero-padded string for a string-backed enum' => [NumericCode::class, '01', null];
        yield 'decimal string for a string-backed enum' => [NumericCode::class, '1.0', null];
        yield 'array for a string-backed enum' => [NumericCode::class, [], null];
    }

    /**
     * Test that a value resolves to a case exactly when Laravel's enum rule
     * accepts it.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @param  mixed  $value
     * @param  \BackedEnum|null  $expected
     * @return void
     */
    #[DataProvider('losslessValueProvider')]
    public function testResolvesACaseExactlyWhenLaravelsEnumRuleAccepts(string $enum, mixed $value, ?\BackedEnum $expected): void
    {
        $passes = Validator::make(['value' => $value], ['value' => [Rule::enum($enum)]])->passes();

        self::assertSame($expected !== null, $passes);
        self::assertSame($passes, BackedEnumValue::tryFrom($enum, $value) !== null);
    }
}
