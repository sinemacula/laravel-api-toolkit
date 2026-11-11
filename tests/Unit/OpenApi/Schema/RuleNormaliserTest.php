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
use Tests\Fixtures\Enums\SerialisedStatus;
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
     * Test that an array element carrying a pipe stays one rule.
     *
     * @return void
     */
    public function testArrayElementIsNotSplitOnPipes(): void
    {
        self::assertSame(['required', 'regex:/^(a|b)$/'], (new RuleNormaliser)->normalise(['required', 'regex:/^(a|b)$/']));
    }

    /**
     * Test that an empty string array element is dropped.
     *
     * @return void
     */
    public function testEmptyArrayElementIsDropped(): void
    {
        self::assertSame(['required', 'string'], (new RuleNormaliser)->normalise(['required', '', 'string']));
    }

    /**
     * Test that an Enum rule expands to an enum-class token.
     *
     * @return void
     */
    public function testEnumObjectExpandsToEnumClassToken(): void
    {
        self::assertSame(['enum-class:' . UserStatus::class], (new RuleNormaliser)->normalise([Rule::enum(UserStatus::class)]));
    }

    /**
     * Test that a narrowed string-backed Enum lists its accepted values.
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
     * Test that a narrowed int-backed Enum lists its values as integers.
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
     * Test that a narrowed Enum lists awkward values exactly.
     *
     * @return void
     */
    public function testNarrowedEnumListsValuesExactly(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['string', 'enum-values:["a,b","say \"hi\""]'], $normaliser->normalise(Rule::enum(Punctuation::class)->except(Punctuation::PLAIN)));
        self::assertSame(['string', 'enum-values:["dir\\\"]'], $normaliser->normalise(Rule::enum(Escaped::class)->only(Escaped::TRAILING)));
    }

    /**
     * Test that a narrowed Enum lists its backing values, not serialised ones.
     *
     * @return void
     */
    public function testNarrowedEnumListsBackingValuesNotItsSerialisedForm(): void
    {
        self::assertSame(['string', 'enum-values:["active"]'], (new RuleNormaliser)->normalise(Rule::enum(SerialisedStatus::class)->only(SerialisedStatus::ACTIVE)));
    }

    /**
     * Test that an Enum narrowed to no case lists no values.
     *
     * @return void
     */
    public function testEnumNarrowedToNoCaseExpandsToAnEmptyList(): void
    {
        $normaliser = new RuleNormaliser;

        self::assertSame(['string', 'enum-values:[]'], $normaliser->normalise(Rule::enum(UserStatus::class)->except(UserStatus::cases())));
        self::assertSame(['integer', 'enum-values:[]'], $normaliser->normalise(Rule::enum(UserLevel::class)->only(UserStatus::ACTIVE)));
        self::assertSame(['string', 'enum-values:[]'], $normaliser->normalise((new PureEnum(Platform::class))->except(Platform::cases())));
    }

    /**
     * Test that only() wins when an Enum has both only() and except().
     *
     * @return void
     */
    public function testEnumOnlyTakesPrecedenceOverExcept(): void
    {
        $rule = Rule::enum(UserStatus::class)->only([UserStatus::ACTIVE, UserStatus::BANNED])->except(UserStatus::ACTIVE);

        self::assertSame(['string', 'enum-values:["active","banned"]'], (new RuleNormaliser)->normalise($rule));
    }

    /**
     * Test that an Enum still admitting every case references the component.
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
     * Test that a narrowed Enum over a pure enum references the component.
     *
     * @return void
     */
    public function testNarrowedEnumOverPureEnumExpandsToEnumClassToken(): void
    {
        self::assertSame(['enum-class:' . DocumentFormat::class], (new RuleNormaliser)->normalise(Rule::enum(DocumentFormat::class)->only(DocumentFormat::PDF)));
    }

    /**
     * Test that an unreadable only() or except() references the component.
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
     * Test that an In rule expands to its quoted in token.
     *
     * @return void
     */
    public function testInObjectExpandsToQuotedInToken(): void
    {
        self::assertSame(['required', 'in:"a","b"'], (new RuleNormaliser)->normalise(['required', Rule::in(['a', 'b'])]));
    }

    /**
     * Test that an In rule's token is not split on a pipe in a value.
     *
     * @return void
     */
    public function testInObjectTokenIsNotSplitOnPipes(): void
    {
        self::assertSame(['in:"a|b"'], (new RuleNormaliser)->normalise(Rule::in(['a|b'])));
    }

    /**
     * Test that a NotIn rule yields no tokens.
     *
     * @return void
     */
    public function testNotInObjectYieldsNoTokens(): void
    {
        self::assertSame([], (new RuleNormaliser)->normalise(Rule::notIn(['a'])));
    }

    /**
     * Test that a PureEnum rule expands to the same enum-class token.
     *
     * @return void
     */
    public function testPureEnumObjectExpandsToEnumClassToken(): void
    {
        self::assertSame(['nullable', 'enum-class:' . Platform::class], (new RuleNormaliser)->normalise(['nullable', new PureEnum(Platform::class)]));
    }

    /**
     * Test that a narrowed PureEnum lists the names it accepts.
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
     * Test that a PureEnum naming every case references the component.
     *
     * @return void
     */
    public function testPureEnumOnlyNamingEveryCaseExpandsToEnumClassToken(): void
    {
        self::assertSame(['enum-class:' . Platform::class], (new RuleNormaliser)->normalise((new PureEnum(Platform::class))->only(Platform::cases())));
    }

    /**
     * Test that a Password rule expands to a string and its minimum.
     *
     * @return void
     */
    public function testPasswordObjectExpandsToStringWithMinimum(): void
    {
        self::assertSame(['string', 'min:8'], (new RuleNormaliser)->normalise([Password::defaults()]));
    }

    /**
     * Test that each exclude rule object expands to its bare name.
     *
     * @return void
     */
    public function testExcludeRuleObjectsExpandToTheirRuleNames(): void
    {
        $tokens = (new RuleNormaliser)->normalise([
            Rule::excludeIf(false),
            Rule::excludeUnless(true),
            Rule::excludeWith('a'),
            Rule::excludeWithout('b'),
            Rule::notIn(['x']),
        ]);

        self::assertSame(['exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without'], $tokens);
    }

    /**
     * Test that an unwrapped object definition is expanded.
     *
     * @return void
     */
    public function testObjectDefinitionIsExpandedDirectly(): void
    {
        self::assertSame(['enum-class:' . UserStatus::class], (new RuleNormaliser)->normalise(Rule::enum(UserStatus::class)));
    }

    /**
     * Test that an Enum over a non-enum class yields no tokens.
     *
     * @return void
     */
    public function testEnumWithNonEnumTypeYieldsNoTokens(): void
    {
        self::assertSame([], (new RuleNormaliser)->normalise(Rule::enum(\stdClass::class)));
    }

    /**
     * Test that a zero-minimum Password yields only a string token.
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
     * Test that a mixed array of tokens and objects is flattened in order.
     *
     * @return void
     */
    public function testMixedArrayFlattensTokensAndObjects(): void
    {
        $tokens = (new RuleNormaliser)->normalise(['required', Password::defaults(), 'confirmed']);

        self::assertSame(['required', 'string', 'min:8', 'confirmed'], $tokens);
    }

    /**
     * Test that an unrecognised rule object is dropped.
     *
     * @return void
     */
    public function testUnrecognisedObjectIsDropped(): void
    {
        $tokens = (new RuleNormaliser)->normalise(['string', new \stdClass]);

        self::assertSame(['string'], $tokens);
    }

    /**
     * Test that a scalar non-string definition yields no tokens.
     *
     * @return void
     */
    public function testScalarDefinitionYieldsNoTokens(): void
    {
        self::assertSame([], (new RuleNormaliser)->normalise(5));
    }
}
