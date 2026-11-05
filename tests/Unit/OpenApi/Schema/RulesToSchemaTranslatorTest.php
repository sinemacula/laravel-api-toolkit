<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\Punctuation;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\OpenApi\ArticleRequestInput;
use Tests\TestCase;

/**
 * Tests for the rules-to-schema translator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RulesToSchemaTranslator::class)]
final class RulesToSchemaTranslatorTest extends TestCase
{
    /**
     * Test that the representative rules source translates to the full object
     * schema spanning presence, size, array items, enum, format, and the
     * confirmation sibling.
     *
     * @return void
     */
    public function testRepresentativeRulesTranslateToFullObjectSchema(): void
    {
        $schema = $this->translator()->translate(ArticleRequestInput::rules());

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'title'                 => ['type' => 'string', 'maxLength' => 255],
                'summary'               => ['type' => ['string', 'null']],
                'tags'                  => ['type' => 'array', 'items' => ['type' => 'string']],
                'status'                => ['$ref' => '#/components/schemas/UserStatus'],
                'platform'              => ['anyOf' => [['$ref' => '#/components/schemas/Platform'], ['type' => 'null']]],
                'visibility'            => ['type' => 'string', 'enum' => ['draft', 'published']],
                'level'                 => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'rank'                  => ['type' => 'integer', 'enum' => [2, 3]],
                'standing'              => ['type' => ['string', 'null'], 'enum' => ['active', 'banned', null]],
                'email'                 => ['type' => 'string', 'format' => 'email'],
                'password'              => ['type' => 'string', 'minLength' => 8],
                'password_confirmation' => ['type' => 'string'],
            ],
            'required' => ['title', 'email', 'password'],
        ], $schema);
    }

    /**
     * Test that a narrowed pure enum field documents only the names it accepts
     * inline, rather than referencing a component that lists every case.
     *
     * @return void
     */
    public function testNarrowedPureEnumFieldListsOnlyTheNamesItAccepts(): void
    {
        $schema = $this->translator()->translate(['platform' => [(new PureEnum(Platform::class))->except(Platform::WEB)]]);

        self::assertSame(['type' => 'string', 'enum' => ['mobile_app', 'desktop']], $schema['properties']['platform']);
    }

    /**
     * Provide narrowed Enum rules over string- and int-backed enums, values
     * carrying commas and quotes included.
     *
     * @return iterable<string, array{0: \Illuminate\Validation\Rules\Enum, 1: class-string<\BackedEnum>}>
     */
    public static function narrowedEnumRules(): iterable
    {
        yield 'string-backed only' => [Rule::enum(UserStatus::class)->only([UserStatus::BANNED, UserStatus::INACTIVE]), UserStatus::class];
        yield 'string-backed except' => [Rule::enum(UserStatus::class)->except(UserStatus::ACTIVE), UserStatus::class];
        yield 'int-backed only' => [Rule::enum(UserLevel::class)->only(UserLevel::SILVER), UserLevel::class];
        yield 'int-backed except' => [Rule::enum(Magnitude::class)->except(Magnitude::ONE), Magnitude::class];
        yield 'only and except' => [Rule::enum(UserLevel::class)->only([UserLevel::BRONZE, UserLevel::GOLD])->except(UserLevel::GOLD), UserLevel::class];
        yield 'commas and quotes' => [Rule::enum(Punctuation::class)->except(Punctuation::PLAIN), Punctuation::class];
    }

    /**
     * Test that a narrowed Enum field's exported enum lists exactly the case
     * values the validator accepts under that rule.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @param  class-string<\BackedEnum>  $enum
     * @return void
     */
    #[DataProvider('narrowedEnumRules')]
    public function testNarrowedEnumFieldListsExactlyTheValuesTheValidatorAccepts(Enum $rule, string $enum): void
    {
        $schema = $this->translator()->translate(['f' => [$rule]])['properties']['f'];

        $accepted = array_values(array_filter(
            array_column($enum::cases(), 'value'),
            static fn (int|string $value): bool => Validator::make(['f' => $value], ['f' => [$rule]])->passes(),
        ));

        self::assertNotSame([], $accepted);
        self::assertArrayNotHasKey('$ref', $schema);
        self::assertSame($accepted, $schema['enum']);
    }

    /**
     * Test that the pipe-string and array rule forms translate identically.
     *
     * @return void
     */
    public function testPipeAndArrayFormsAgree(): void
    {
        $translator = $this->translator();

        self::assertSame(
            $translator->translate(['f' => 'required|string']),
            $translator->translate(['f' => ['required', 'string']]),
        );
    }

    /**
     * Test that an In rule object exports the same enum as the equivalent
     * value-list string.
     *
     * @return void
     */
    public function testInObjectAndValueListStringAgree(): void
    {
        $translator = $this->translator();
        $schema     = $translator->translate(['f' => [Rule::in(['a', 'b'])]]);

        self::assertSame(['type' => 'object', 'properties' => ['f' => ['enum' => ['a', 'b']]]], $schema);
        self::assertSame($translator->translate(['f' => 'in:a,b']), $schema);
    }

    /**
     * Test that values carrying commas and quotes round-trip exactly through
     * both the In object and its quoted string form.
     *
     * @return void
     */
    public function testInValuesWithCommasAndQuotesRoundTrip(): void
    {
        $translator = $this->translator();
        $values     = ['a,b', 'say "hi"'];
        $expected   = ['type' => 'object', 'properties' => ['f' => ['type' => 'string', 'enum' => $values]]];

        self::assertSame($expected, $translator->translate(['f' => ['string', Rule::in($values)]]));
        self::assertSame($expected, $translator->translate(['f' => ['string', 'in:"a,b","say ""hi"""']]));
    }

    /**
     * Test that an In object built from a string-backed enum's cases exports
     * the backing values as strings.
     *
     * @return void
     */
    public function testInObjectFromStringBackedEnumCasesExportsTheirValues(): void
    {
        $schema = $this->translator()->translate(['f' => ['string', Rule::in(array_column(UserStatus::cases(), 'value'))]]);

        self::assertSame(['type' => 'string', 'enum' => ['active', 'inactive', 'banned']], $schema['properties']['f']);
    }

    /**
     * Test that an In object built from an int-backed enum's cases on an
     * integer field exports the backing values as integers.
     *
     * @return void
     */
    public function testInObjectFromIntBackedEnumCasesExportsIntegers(): void
    {
        $schema = $this->translator()->translate(['f' => ['integer', Rule::in(array_column(UserLevel::cases(), 'value'))]]);

        self::assertSame(['type' => 'integer', 'enum' => [1, 2, 3]], $schema['properties']['f']);
    }

    /**
     * Test that an In object on an array field constrains its items, and that a
     * `field.*` sibling is required alongside that constraint rather than
     * replacing it.
     *
     * @return void
     */
    public function testInObjectOnArrayFieldConstrainsItsItems(): void
    {
        $schema = $this->translator()->translate([
            'tags'   => ['array', Rule::in(['a', 'b'])],
            'tags.*' => ['string', Rule::in(['b', 'c'])],
        ]);

        self::assertSame([
            'type'  => 'array',
            'items' => ['allOf' => [['enum' => ['a', 'b']], ['type' => 'string', 'enum' => ['b', 'c']]]],
        ], $schema['properties']['tags']);
    }

    /**
     * Test that numeric values on an array field stay satisfiable when its
     * items are typed as integers, as the validator accepts either form.
     *
     * @return void
     */
    public function testNumericInObjectOnArrayFieldAdmitsTypedItems(): void
    {
        $schema = $this->translator()->translate(['ids' => ['array', Rule::in([1, 2])], 'ids.*' => 'integer']);

        self::assertSame([
            'type'  => 'array',
            'items' => ['allOf' => [['enum' => ['1', 1, '2', 2]], ['type' => 'integer']]],
        ], $schema['properties']['ids']);
    }

    /**
     * Test that a NotIn rule object, which has no schema translation,
     * contributes nothing while the field's other rules survive.
     *
     * @return void
     */
    public function testNotInObjectContributesNothing(): void
    {
        $schema = $this->translator()->translate(['f' => ['string', Rule::notIn(['a'])]]);

        self::assertSame(['type' => 'string'], $schema['properties']['f']);
    }

    /**
     * Test that dotted keys weave into a nested object property carrying its
     * own required list.
     *
     * @return void
     */
    public function testDottedKeysBuildNestedObject(): void
    {
        $schema = $this->translator()->translate(['address.city' => 'required|string']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'address' => [
                    'type'       => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required'   => ['city'],
                ],
            ],
        ], $schema);
    }

    /**
     * Test that a field.* sibling supplies the items schema of an array field.
     *
     * @return void
     */
    public function testStarKeyBuildsArrayItems(): void
    {
        $schema = $this->translator()->translate(['tags' => ['array'], 'tags.*' => 'integer']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ], $schema);
    }

    /**
     * Test that a field.*.child grid weaves an array of objects, nesting each
     * child property and its required list under the items node.
     *
     * @return void
     */
    public function testStarChildGridBuildsArrayOfObjects(): void
    {
        $schema = $this->translator()->translate([
            'items'       => ['array'],
            'items.*.sku' => 'required|string',
            'items.*.qty' => 'integer',
        ]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'qty' => ['type' => 'integer'],
                        ],
                        'required' => ['sku'],
                    ],
                ],
            ],
        ], $schema);
    }

    /**
     * Test that a confirmed field gains a confirmation sibling of the same base
     * type.
     *
     * @return void
     */
    public function testConfirmedFieldGainsSibling(): void
    {
        $schema = $this->translator()->translate(['password' => ['required', 'string', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string'],
                'password_confirmation' => ['type' => 'string'],
            ],
            'required' => ['password'],
        ], $schema);
    }

    /**
     * Test that a confirmed field carrying no scalar type gains a string
     * confirmation sibling.
     *
     * @return void
     */
    public function testConfirmedTypelessFieldGainsStringSibling(): void
    {
        $schema = $this->translator()->translate(['status' => ['in:active,inactive', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'status'              => ['enum' => ['active', 'inactive']],
                'status_confirmation' => ['type' => 'string'],
            ],
        ], $schema);
    }

    /**
     * Test that a confirmed field's confirmation sibling mirrors a non-string
     * base type verbatim.
     *
     * @return void
     */
    public function testConfirmedTypedFieldMirrorsBaseType(): void
    {
        $schema = $this->translator()->translate(['count' => ['integer', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'count'              => ['type' => 'integer'],
                'count_confirmation' => ['type' => 'integer'],
            ],
        ], $schema);
    }

    /**
     * Test that an unrecognised rule is skipped while its field is kept.
     *
     * @return void
     */
    public function testUnknownRuleIsSkippedKeepingField(): void
    {
        $schema = $this->translator()->translate(['f' => 'string|foobar']);

        self::assertSame([
            'type'       => 'object',
            'properties' => ['f' => ['type' => 'string']],
        ], $schema);
    }

    /**
     * Test that keys nested more than one level deep are woven best-effort.
     *
     * @return void
     */
    public function testDeeperNestingIsWovenBestEffort(): void
    {
        $schema = $this->translator()->translate(['a.b.c' => 'string']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'a' => [
                    'type'       => 'object',
                    'properties' => [
                        'b' => [
                            'type'       => 'object',
                            'properties' => ['c' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ], $schema);
    }

    /**
     * Build a translator over the real normaliser and field builder.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator
     */
    private function translator(): RulesToSchemaTranslator
    {
        return new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder);
    }
}
