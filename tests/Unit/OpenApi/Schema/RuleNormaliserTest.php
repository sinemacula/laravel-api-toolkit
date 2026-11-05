<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\DocumentFormat;
use Tests\Fixtures\Enums\Escaped;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\Punctuation;
use Tests\Fixtures\Enums\UserLevel;
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
     * Test that an array element carrying a pipe stays one rule, as the
     * validator does not split array elements, so a regex alternation survives
     * intact.
     *
     * @return void
     */
    public function testArrayElementIsNotSplitOnPipes(): void
    {
        self::assertSame(['required', 'regex:/^(a|b)$/'], (new RuleNormaliser)->normalise(['required', 'regex:/^(a|b)$/']));
    }

    /**
     * Test that an empty string array element is dropped, as the validator
     * skips an empty rule.
     *
     * @return void
     */
    public function testEmptyArrayElementIsDropped(): void
    {
        self::assertSame(['required', 'string'], (new RuleNormaliser)->normalise(['required', '', 'string']));
    }

    /**
     * Test that an Enum rule object expands to an enum-class token naming the
     * enum class rather than inlining its backing values.
     *
     * @return void
     */
    public function testEnumObjectExpandsToEnumClassToken(): void
    {
        self::assertSame(['enum-class:' . UserStatus::class], (new RuleNormaliser)->normalise([Rule::enum(UserStatus::class)]));
    }

    /**
     * Test that an Enum narrowed by only() or except() on a string-backed enum
     * expands to a string token and an enum-values token listing the values it
     * accepts, in case declaration order.
     *
     * @return void
     */
    public function testNarrowedStringBackedEnumExpandsToTheValuesItAccepts(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['string', 'enum-values:["active","banned"]'], $normaliser->normalise(Rule::enum(UserStatus::class)->only([UserStatus::BANNED, UserStatus::ACTIVE])));
        self::assertSame(['string', 'enum-values:["active","inactive"]'], $normaliser->normalise(Rule::enum(UserStatus::class)->except(UserStatus::BANNED)));
    }

    /**
     * Test that an Enum narrowed by only() or except() on an int-backed enum
     * expands to an integer token and an enum-values token listing the values
     * it accepts as integers.
     *
     * @return void
     */
    public function testNarrowedIntBackedEnumExpandsToTheValuesItAccepts(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['integer', 'enum-values:[3]'], $normaliser->normalise(Rule::enum(UserLevel::class)->only(UserLevel::GOLD)));
        self::assertSame(['integer', 'enum-values:[1,-3]'], $normaliser->normalise(Rule::enum(Magnitude::class)->except(Magnitude::ZERO)));
    }

    /**
     * Test that a narrowed Enum lists values carrying commas, quotes, or a
     * trailing backslash exactly.
     *
     * @return void
     */
    public function testNarrowedEnumListsValuesExactly(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['string', 'enum-values:["a,b","say \\"hi\\""]'], $normaliser->normalise(Rule::enum(Punctuation::class)->except(Punctuation::PLAIN)));
        self::assertSame(['string', 'enum-values:["dir\\\\"]'], $normaliser->normalise(Rule::enum(Escaped::class)->only(Escaped::TRAILING)));
    }

    /**
     * Test that an Enum or PureEnum narrowed to no case expands to an empty
     * enum-values list, since the rule accepts nothing.
     *
     * @return void
     */
    public function testEnumNarrowedToNoCaseExpandsToAnEmptyList(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['string', 'enum-values:[]'], $normaliser->normalise(Rule::enum(UserStatus::class)->except(UserStatus::cases())));
        self::assertSame(['integer', 'enum-values:[]'], $normaliser->normalise(Rule::enum(UserLevel::class)->only('3')));
        self::assertSame(['string', 'enum-values:[]'], $normaliser->normalise((new PureEnum(Platform::class))->except(Platform::cases())));
    }

    /**
     * Test that an Enum given both only() and except() accepts what only()
     * names, ignoring except() as the rule does.
     *
     * @return void
     */
    public function testEnumOnlyTakesPrecedenceOverExcept(): void
    {
        $rule = Rule::enum(UserStatus::class)->only([UserStatus::ACTIVE, UserStatus::BANNED])->except(UserStatus::ACTIVE);

        self::assertSame(['string', 'enum-values:["active","banned"]'], (new RuleNormaliser)->normalise($rule));
    }

    /**
     * Test that an Enum whose narrowing still admits every case references the
     * enum's component.
     *
     * @return void
     */
    public function testEnumNarrowingAdmittingEveryCaseExpandsToEnumClassToken(): void
    {
        $normaliser = new RuleNormaliser;
        $expected   = ['enum-class:' . UserStatus::class];

        self::assertSame($expected, $normaliser->normalise(Rule::enum(UserStatus::class)->only(UserStatus::cases())));
        self::assertSame($expected, $normaliser->normalise(Rule::enum(UserStatus::class)->except([])));
        self::assertSame($expected, $normaliser->normalise(Rule::enum(UserStatus::class)->except(UserLevel::GOLD)));
    }

    /**
     * Test that an Enum rule over a pure enum references the enum's component
     * even when narrowed, since its cases have no backing values to list.
     *
     * @return void
     */
    public function testNarrowedEnumOverPureEnumExpandsToEnumClassToken(): void
    {
        self::assertSame(['enum-class:' . DocumentFormat::class], (new RuleNormaliser)->normalise(Rule::enum(DocumentFormat::class)->only(DocumentFormat::PDF)));
    }

    /**
     * Test that an Enum whose only() or except() list cannot be read as an
     * array references the enum's component.
     *
     * @return void
     */
    public function testEnumWithUnreadableNarrowingExpandsToEnumClassToken(): void
    {
        $normaliser = new RuleNormaliser;
        $expected   = ['enum-class:' . UserStatus::class];

        foreach (['only', 'except'] as $property) {
            $rule = Rule::enum(UserStatus::class);

            (new \ReflectionProperty($rule, $property))->setValue($rule, UserStatus::ACTIVE);

            self::assertSame($expected, $normaliser->normalise($rule));
        }
    }

    /**
     * Test that an In rule object expands to the quoted in token its string
     * form renders.
     *
     * @return void
     */
    public function testInObjectExpandsToQuotedInToken(): void
    {
        self::assertSame(['required', 'in:"a","b"'], (new RuleNormaliser)->normalise(['required', Rule::in(['a', 'b'])]));
    }

    /**
     * Test that an In rule object's token is not split on a pipe inside one of
     * its values, as the validator does not split it either.
     *
     * @return void
     */
    public function testInObjectTokenIsNotSplitOnPipes(): void
    {
        self::assertSame(['in:"a|b"'], (new RuleNormaliser)->normalise(Rule::in(['a|b'])));
    }

    /**
     * Test that a NotIn rule object, which has no schema translation, yields no
     * tokens.
     *
     * @return void
     */
    public function testNotInObjectYieldsNoTokens(): void
    {
        self::assertSame([], (new RuleNormaliser)->normalise(Rule::notIn(['a'])));
    }

    /**
     * Test that a PureEnum rule object expands to the same enum-class token as
     * an Enum rule, so both reference the enum's named component.
     *
     * @return void
     */
    public function testPureEnumObjectExpandsToEnumClassToken(): void
    {
        self::assertSame(['nullable', 'enum-class:' . Platform::class], (new RuleNormaliser)->normalise(['nullable', new PureEnum(Platform::class)]));
    }

    /**
     * Test that a PureEnum narrowed by only() or except() expands to a string
     * token and an enum-values token listing the names it accepts, since the
     * enum's component lists every case.
     *
     * @return void
     */
    public function testNarrowedPureEnumExpandsToTheNamesItAccepts(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['string', 'enum-values:["web","desktop"]'], $normaliser->normalise((new PureEnum(Platform::class))->only([Platform::DESKTOP, Platform::WEB])));
        self::assertSame(['string', 'enum-values:["mobile_app","desktop"]'], $normaliser->normalise((new PureEnum(Platform::class))->except(Platform::WEB)));
    }

    /**
     * Test that a PureEnum whose only() list names every case still references
     * the enum's component.
     *
     * @return void
     */
    public function testPureEnumOnlyNamingEveryCaseExpandsToEnumClassToken(): void
    {
        self::assertSame(['enum-class:' . Platform::class], (new RuleNormaliser)->normalise((new PureEnum(Platform::class))->only(Platform::cases())));
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
        self::assertSame(['enum-class:' . UserStatus::class], (new RuleNormaliser)->normalise(Rule::enum(UserStatus::class)));
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
