<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use Tests\Fixtures\Enums\UserStatus;
use Tests\TestCase;

/**
 * Tests for the rule normaliser.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RuleNormaliser::class)]
final class RuleNormaliserTest extends TestCase
{
    /**
     * Test that a pipe-delimited string splits into its tokens.
     *
     * @return void
     */
    public function testPipeStringSplitsIntoTokens(): void
    {
        self::assertSame(['required', 'string'], (new RuleNormaliser)->normalise('required|string'));
    }

    /**
     * Test that empty pipe segments are dropped.
     *
     * @return void
     */
    public function testEmptyPipeSegmentsAreDropped(): void
    {
        self::assertSame(['a', 'b'], (new RuleNormaliser)->normalise('a||b'));
    }

    /**
     * Test that an array of tokens is returned flat, re-splitting any element
     * that itself carries pipes.
     *
     * @return void
     */
    public function testArrayFormFlattensAndResplits(): void
    {
        self::assertSame(['required', 'string'], (new RuleNormaliser)->normalise(['required|string']));
    }

    /**
     * Test that an Enum rule object expands to an in token of its backing
     * values.
     *
     * @return void
     */
    public function testEnumObjectExpandsToInToken(): void
    {
        self::assertSame(['in:active,inactive,banned'], (new RuleNormaliser)->normalise([Rule::enum(UserStatus::class)]));
    }

    /**
     * Test that a Password rule object expands to a string token and its
     * minimum length.
     *
     * @return void
     */
    public function testPasswordObjectExpandsToStringWithMinimum(): void
    {
        self::assertSame(['string', 'min:8'], (new RuleNormaliser)->normalise([Password::defaults()]));
    }

    /**
     * Test that an object definition passed directly, not wrapped in an array,
     * is expanded.
     *
     * @return void
     */
    public function testObjectDefinitionIsExpandedDirectly(): void
    {
        self::assertSame(['in:active,inactive,banned'], (new RuleNormaliser)->normalise(Rule::enum(UserStatus::class)));
    }

    /**
     * Test that an Enum whose backing type is a class but not an enum yields no
     * tokens.
     *
     * @return void
     */
    public function testEnumWithNonEnumTypeYieldsNoTokens(): void
    {
        self::assertSame([], (new RuleNormaliser)->normalise(Rule::enum(\stdClass::class)));
    }

    /**
     * Test that a Password whose minimum is zero yields only a string token,
     * omitting the min token.
     *
     * @return void
     */
    public function testPasswordWithZeroMinimumYieldsOnlyString(): void
    {
        $rule = Password::min(1);

        (new \ReflectionProperty($rule, 'min'))->setValue($rule, 0);

        self::assertSame(['string'], (new RuleNormaliser)->normalise($rule));
    }

    /**
     * Test that a mixed array of tokens and objects is fully flattened in
     * order.
     *
     * @return void
     */
    public function testMixedArrayFlattensTokensAndObjects(): void
    {
        $tokens = (new RuleNormaliser)->normalise(['required', Password::defaults(), 'confirmed']);

        self::assertSame(['required', 'string', 'min:8', 'confirmed'], $tokens);
    }

    /**
     * Test that an unrecognised rule object is dropped while its sibling tokens
     * survive.
     *
     * @return void
     */
    public function testUnrecognisedObjectIsDropped(): void
    {
        $tokens = (new RuleNormaliser)->normalise(['string', new \stdClass]);

        self::assertSame(['string'], $tokens);
    }

    /**
     * Test that a non-string, non-array, non-object definition yields no
     * tokens.
     *
     * @return void
     */
    public function testScalarDefinitionYieldsNoTokens(): void
    {
        self::assertSame([], (new RuleNormaliser)->normalise(5));
    }
}
