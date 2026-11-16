<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Rules;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Services\Input\Rules\Enum;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\TestCase;

/**
 * Tests for the payload enum rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Enum::class)]
final class EnumTest extends TestCase
{
    /** @var string The English enum failure for the magnitude key */
    private const string INVALID_MAGNITUDE = 'The selected magnitude is invalid.';

    /**
     * Provide the canonical forms of an int-backed enum value.
     *
     * @return iterable<string, array{mixed, \Tests\Fixtures\Enums\Magnitude}>
     */
    public static function canonicalIntBackingProvider(): iterable
    {
        yield 'integer' => [1, Magnitude::ONE];
        yield 'zero' => [0, Magnitude::ZERO];
        yield 'integer string' => ['1', Magnitude::ONE];
        yield 'zero string' => ['0', Magnitude::ZERO];
        yield 'negative integer string' => ['-3', Magnitude::NEGATIVE];
    }

    /**
     * Test that a canonical int backing value resolves to its case.
     *
     * @param  mixed  $value
     * @param  \Tests\Fixtures\Enums\Magnitude  $expected
     * @return void
     */
    #[DataProvider('canonicalIntBackingProvider')]
    public function testResolvesACanonicalIntBackingValue(mixed $value, Magnitude $expected): void
    {
        self::assertSame($expected, Enum::resolve(Magnitude::class, $value));
    }

    /**
     * Provide values that are not the canonical form of an int-backed enum.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonCanonicalIntBackingProvider(): iterable
    {
        yield 'zero-padded string' => ['01'];
        yield 'leading whitespace' => [' 1'];
        yield 'trailing whitespace' => ['1 '];
        yield 'integral decimal string' => ['1.0'];
        yield 'fractional decimal string' => ['1.9'];
        yield 'exponent string' => ['1e0'];
        yield 'explicit plus sign' => ['+1'];
        yield 'negative zero string' => ['-0'];
        yield 'empty string' => [''];
        yield 'word' => ['one'];
        yield 'integer string above the range' => ['9223372036854775808'];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'integral float' => [1.0];
        yield 'fractional float' => [1.5];
        yield 'unknown integer' => [2];
    }

    /**
     * Test that a non-canonical int backing value resolves to nothing.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('nonCanonicalIntBackingProvider')]
    public function testResolvesNothingForANonCanonicalIntBackingValue(mixed $value): void
    {
        self::assertNull(Enum::resolve(Magnitude::class, $value));
    }

    /**
     * Test that a string-backed enum resolves only from a string.
     *
     * @return void
     */
    public function testResolvesAStringBackedEnumOnlyFromAString(): void
    {
        self::assertSame(StubStatusEnum::ACTIVE, Enum::resolve(StubStatusEnum::class, 'active'));
        self::assertNull(Enum::resolve(StubStatusEnum::class, 'ACTIVE'));
        self::assertNull(Enum::resolve(StubStatusEnum::class, 42));
    }

    /**
     * Test that a pure enum resolves only from a lowercase case name.
     *
     * @return void
     */
    public function testResolvesAPureEnumFromItsLowercaseCaseName(): void
    {
        self::assertSame(Platform::MOBILE_APP, Enum::resolve(Platform::class, 'mobile_app'));
        self::assertNull(Enum::resolve(Platform::class, 'MOBILE_APP'));
        self::assertNull(Enum::resolve(Platform::class, 1));
    }

    /**
     * Test that null, a case and a canonical value pass, and other values not.
     *
     * @return void
     */
    public function testPassesNullACaseAndACanonicalValueOnly(): void
    {
        $rule = new Enum(StubStatusEnum::class);

        self::assertTrue($rule->passes('status', null));
        self::assertTrue($rule->passes('status', StubStatusEnum::INACTIVE));
        self::assertTrue($rule->passes('status', 'inactive'));
        self::assertFalse($rule->passes('status', UserStatus::INACTIVE));
        self::assertFalse($rule->passes('status', 'banned'));
    }

    /**
     * Test that an empty or blank value still fails.
     *
     * @return void
     */
    public function testEmptyAndBlankValuesFail(): void
    {
        $validator = Validator::make(['empty' => '', 'blank' => '  '], [
            'empty' => [new Enum(Magnitude::class)],
            'blank' => [new Enum(Magnitude::class)],
        ]);

        self::assertSame(
            ['empty' => ['The selected empty is invalid.'], 'blank' => ['The selected blank is invalid.']],
            $validator->errors()->toArray(),
        );
    }

    /**
     * Test that the optional and stopping rules keep their meaning.
     *
     * @return void
     */
    public function testOptionalAndStoppingRulesKeepTheirMeaning(): void
    {
        self::assertTrue(Validator::make([], ['magnitude' => ['sometimes', new Enum(Magnitude::class)]])->passes());
        self::assertTrue(Validator::make(['magnitude' => null], ['magnitude' => ['nullable', new Enum(Magnitude::class)]])->passes());

        $validator = Validator::make(['magnitude' => 'x'], ['magnitude' => ['bail', 'integer', new Enum(Magnitude::class)]]);

        self::assertSame(['magnitude' => ['The magnitude field must be an integer.']], $validator->errors()->toArray());
    }

    /**
     * Test that the failure collapses with Laravel's enum rule into one.
     *
     * @return void
     */
    public function testFailureCollapsesWithLaravelsEnumRule(): void
    {
        $rules     = ['magnitude' => [Rule::enum(Magnitude::class), new Enum(Magnitude::class)]];
        $validator = Validator::make(['magnitude' => 'one'], $rules);

        self::assertSame(['magnitude' => [self::INVALID_MAGNITUDE]], $validator->errors()->toArray());
    }

    /**
     * Test that one custom message rewords both enum rules.
     *
     * @return void
     */
    public function testCustomMessageRewordsBothEnumRules(): void
    {
        $rules     = ['magnitude' => [Rule::enum(Magnitude::class), new Enum(Magnitude::class)]];
        $validator = Validator::make(['magnitude' => 'one'], $rules, ['magnitude.enum' => 'Pick a real magnitude.']);

        self::assertSame(['magnitude' => ['Pick a real magnitude.']], $validator->errors()->toArray());
    }

    /**
     * Test that the message follows the attribute names and the locale.
     *
     * @return void
     */
    public function testMessageFollowsTheAttributeNamesAndTheLocale(): void
    {
        Lang::addLines(['validation.enum' => 'Der Wert für :attribute ist ungültig.', 'validation.attributes.magnitude' => 'Größe'], 'de');
        App::setLocale('de');

        $validator = Validator::make(['magnitude' => '01'], ['magnitude' => [new Enum(Magnitude::class)]]);

        self::assertSame(['magnitude' => ['Der Wert für Größe ist ungültig.']], $validator->errors()->toArray());
    }
}
