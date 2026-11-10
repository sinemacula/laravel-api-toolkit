<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator as SchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Schema\SchemaObjectNormaliser;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\Escaped;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\Punctuation;
use Tests\Fixtures\Enums\SerialisedStatus;
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
                'retired'               => ['type' => 'string', 'enum' => []],
                'email'                 => ['type' => 'string', 'format' => 'email'],
                'password'              => ['type' => 'string', 'minLength' => 8],
                'password_confirmation' => ['type' => 'string'],
            ],
            'required' => ['title', 'email', 'password', 'password_confirmation'],
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
        yield 'trailing backslash' => [Rule::enum(Escaped::class)->only(Escaped::TRAILING), Escaped::class];
        yield 'serialised enum' => [Rule::enum(SerialisedStatus::class)->only(SerialisedStatus::ACTIVE), SerialisedStatus::class];
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
        self::assertIsArray($schema);
        self::assertArrayNotHasKey('$ref', $schema);
        self::assertSame($accepted, $schema['enum']);
    }

    /**
     * Test that a narrowed int-backed Enum on a field the rules also declare a
     * string lists its values as the strings the validator accepts there.
     *
     * @return void
     */
    public function testNarrowedIntBackedEnumOnAStringFieldListsStrings(): void
    {
        $rules  = ['level' => ['string', Rule::enum(UserLevel::class)->only(UserLevel::SILVER)]];
        $schema = $this->translator()->translate($rules)['properties']['level'];

        self::assertTrue(Validator::make(['level' => '2'], $rules)->passes());
        self::assertFalse(Validator::make(['level' => 2], $rules)->passes());
        self::assertSame(['type' => 'string', 'enum' => ['2']], $schema);
    }

    /**
     * Test that a field whose enum rule is narrowed to no case documents an
     * empty enum, which no value satisfies, as the rule accepts none, keeping
     * only null on a nullable field.
     *
     * @return void
     */
    public function testEnumFieldNarrowedToNoCaseAcceptsNothing(): void
    {
        $rule   = Rule::enum(UserLevel::class)->except(UserLevel::cases());
        $schema = $this->translator()->translate([
            'level'    => [$rule],
            'optional' => ['nullable', $rule],
            'platform' => [(new PureEnum(Platform::class))->except(Platform::cases())],
        ])['properties'];

        foreach (UserLevel::cases() as $case) {
            self::assertFalse(Validator::make(['level' => $case->value], ['level' => [$rule]])->passes());
        }

        self::assertSame(['type' => 'integer', 'enum' => []], $schema['level']);
        self::assertSame(['type' => ['integer', 'null'], 'enum' => [null]], $schema['optional']);
        self::assertSame(['type' => 'string', 'enum' => []], $schema['platform']);
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
     * Test that a regex array element carrying an alternation exports its whole
     * pattern rather than being split on the pipe.
     *
     * @return void
     */
    public function testRegexArrayElementWithAlternationKeepsItsPattern(): void
    {
        $schema = $this->translator()->translate(['f' => ['required', 'string', 'regex:/^(a|b)$/']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => ['f' => ['type' => 'string', 'pattern' => '^(a|b)$']],
            'required'   => ['f'],
        ], $schema);
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
     * own required list, the object required for holding a required field.
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
            'required' => ['address'],
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
     * Test that a required confirmed field gains a confirmation sibling of the
     * same base type, required alongside it.
     *
     * @return void
     */
    public function testRequiredConfirmedFieldRequiresItsSibling(): void
    {
        $schema = $this->translator()->translate(['password' => ['required', 'string', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string'],
                'password_confirmation' => ['type' => 'string'],
            ],
            'required' => ['password', 'password_confirmation'],
        ], $schema);
    }

    /**
     * Test that an optional confirmed field requires its confirmation sibling
     * only when the field itself is sent.
     *
     * @return void
     */
    public function testOptionalConfirmedFieldRequiresItsSiblingWhenSent(): void
    {
        $schema = $this->translator()->translate(['password' => ['sometimes', 'required', 'string', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string'],
                'password_confirmation' => ['type' => 'string'],
            ],
            'dependentRequired' => ['password' => ['password_confirmation']],
        ], $schema);
    }

    /**
     * Test that a confirmed rule naming its confirmation field documents and
     * requires that field rather than the default sibling.
     *
     * @return void
     */
    public function testConfirmedRuleNamingItsFieldRequiresThatField(): void
    {
        $schema = $this->translator()->translate([
            'password'        => ['required', 'string', 'confirmed:repeat_password'],
            'repeat_password' => ['string', 'max:64'],
        ]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'        => ['type' => 'string'],
                'repeat_password' => ['type' => 'string', 'maxLength' => 64],
            ],
            'required' => ['password', 'repeat_password'],
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
            'dependentRequired' => ['status' => ['status_confirmation']],
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
            'dependentRequired' => ['count' => ['count_confirmation']],
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
     * Test that a prohibited field is the false schema, left out of the
     * required list whatever its other rules say, and loses its children and
     * its confirmation sibling.
     *
     * @return void
     */
    public function testProhibitedFieldIsTheFalseSchemaAndNeverRequired(): void
    {
        $schema = $this->translator()->translate([
            'name'        => 'required|string',
            'email'       => ['required', 'prohibited', 'confirmed'],
            'meta'        => 'prohibited',
            'meta.source' => 'required|string',
            'tags'        => ['array', Rule::in(['a'])],
            'tags.*'      => 'prohibited',
            'kind'        => 'required|string|prohibited_if:name,x',
            'notes'       => 'string|prohibited_unless:name,y',
        ]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'name'  => ['type' => 'string'],
                'email' => false,
                'meta'  => false,
                'tags'  => ['type' => 'array', 'items' => ['allOf' => [['enum' => ['a']], false]]],
                'kind'  => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['name', 'kind'],
        ], $schema);
    }

    /**
     * Test that a body whose root rules prohibit it translates to an empty
     * schema, documenting no body.
     *
     * @return void
     */
    public function testProhibitedRootTranslatesToAnEmptySchema(): void
    {
        self::assertSame([], $this->translator()->translate(['' => 'prohibited', 'name' => 'string']));
    }

    /**
     * Test that an array's own item constraint stands alone when its `field.*`
     * sibling adds none, rather than being combined with an empty schema.
     *
     * @return void
     */
    public function testUnconstrainedStarSiblingKeepsTheOwnItemConstraint(): void
    {
        $schema = $this->translator()->translate(['tags' => ['array', Rule::in(['a', 'b'])], 'tags.*' => 'sometimes']);

        self::assertSame(['type' => 'array', 'items' => ['enum' => ['a', 'b']]], $schema['properties']['tags']);
    }

    /**
     * Test that a field validated only by untranslatable rules is kept with an
     * empty schema, as is an unconstrained `field.*` sibling.
     *
     * @return void
     */
    public function testUntranslatableFieldsKeepAnEmptySchema(): void
    {
        $schema = $this->translator()->translate(['flag' => 'accepted', 'list' => 'array', 'list.*' => 'nullable']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'flag' => [],
                'list' => ['type' => 'array', 'items' => []],
            ],
        ], $schema);
    }

    /**
     * Test that the conditional presence rules of the request example document
     * which field one field requires and that one of two fields must be sent.
     *
     * @return void
     */
    public function testConditionalPresenceRulesRelateTheFieldsTheyName(): void
    {
        $schema = $this->translator()->translate([
            'credential' => ['sometimes', 'required', 'string'],
            'member_id'  => ['required_with:credential', 'required_without:group_id', 'nullable', 'uuid'],
            'group_id'   => ['nullable', 'uuid'],
        ]);

        self::assertSame(['credential' => ['member_id']], $schema['dependentRequired']);
        self::assertSame([['required' => ['member_id']], ['required' => ['group_id']]], $schema['anyOf']);
        self::assertArrayNotHasKey('required', $schema);
    }

    /**
     * Provide rules carrying confirmed and conditional presence rules, each
     * with request bodies the validator accepts and refuses on presence alone.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: list<array<string, mixed>>}>
     */
    public static function presenceRules(): iterable
    {
        $uuid = '7b6f0c1e-5d3a-4c2b-9a8e-1f2d3c4b5a69';

        yield 'with and without' => [
            [
                'credential' => ['sometimes', 'required', 'string'],
                'member_id'  => ['required_with:credential', 'required_without:group_id', 'nullable', 'uuid'],
                'group_id'   => ['nullable', 'uuid'],
            ],
            [[], ['credential' => 'x'], ['credential' => 'x', 'member_id' => $uuid], ['group_id' => $uuid], ['member_id' => $uuid], ['credential' => 'x', 'group_id' => $uuid]],
        ];

        yield 'with all and without all' => [
            ['a' => 'string', 'b' => 'string', 'f' => 'required_with_all:a,b|string', 'g' => 'required_without_all:a,b|string'],
            [[], ['a' => 'x'], ['b' => 'x'], ['g' => 'x'], ['a' => 'x', 'b' => 'x'], ['a' => 'x', 'b' => 'x', 'f' => 'x'], ['g' => 'x', 'a' => 'x', 'b' => 'x', 'f' => 'x']],
        ];

        yield 'confirmed' => [
            ['password' => 'required|string|confirmed', 'pin' => 'sometimes|string|confirmed:pin_repeat'],
            [
                ['password' => 'x'],
                ['password' => 'x', 'password_confirmation' => 'x'],
                ['password' => 'x', 'password_confirmation' => 'x', 'pin' => 'y'],
                ['password' => 'x', 'password_confirmation' => 'x', 'pin' => 'y', 'pin_repeat' => 'y'],
            ],
        ];

        yield 'exclude and prohibited' => [
            ['a' => 'string', 'f' => 'exclude_if:a,x|required_with:a|string', 'g' => 'prohibited|required_without:a'],
            [[], ['a' => 'x'], ['a' => 'y', 'f' => 'z'], ['g' => 'z', 'a' => 'x']],
        ];

        yield 'array items' => [
            ['items' => 'required|array', 'items.*.a' => 'required_without:items.*.b|string', 'items.*.b' => 'string'],
            [['items' => [['a' => 'x'], ['b' => 'x']]], ['items' => [['a' => 'x', 'b' => 'y']]], ['items' => [['b' => 'x'], ['c' => 'x']]]],
        ];
    }

    /**
     * Test that the request schema accepts exactly the bodies the validator
     * accepts under confirmed and conditional presence rules.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<array<string, mixed>>  $bodies
     * @return void
     */
    #[DataProvider('presenceRules')]
    public function testPresenceRulesAcceptWhatTheValidatorAccepts(array $rules, array $bodies): void
    {
        $schema = $this->translator()->translate($rules);

        foreach ($bodies as $body) {
            self::assertSame(
                Validator::make($body, $rules)->passes(),
                $this->isAcceptedBySchema($schema, $body),
                sprintf('The schema and the validator disagree on %s.', json_encode($body, JSON_THROW_ON_ERROR)),
            );
        }
    }

    /**
     * Test that the confirmed and conditional presence rules of a nested object
     * or an array item reference that object's own fields.
     *
     * @return void
     */
    public function testNestedPresenceRulesReferenceTheirOwnObject(): void
    {
        $schema = $this->translator()->translate([
            'user.password' => ['required', 'string', 'confirmed:user.repeat'],
            'user.a'        => 'required_with:user.b',
            'user.b'        => 'string',
            'items'         => 'array',
            'items.*.a'     => 'required_without:items.*.b',
            'items.*.b'     => 'string',
            'slots.1.a'     => 'required_with:slots.1.b',
            'slots.1.b'     => 'string',
        ])['properties'];

        self::assertSame(['password', 'repeat'], $schema['user']['required']);
        self::assertSame(['type' => 'string'], $schema['user']['properties']['repeat']);
        self::assertSame(['b' => ['a']], $schema['user']['dependentRequired']);
        self::assertSame([['required' => ['a']], ['required' => ['b']]], $schema['items']['items']['anyOf']);
        self::assertSame(['b' => ['a']], $schema['slots']['properties']['1']['dependentRequired']);
    }

    /**
     * Test that a presence rule referencing a field outside its object, or one
     * the rules never declare, is skipped rather than invented.
     *
     * @return void
     */
    public function testPresenceRuleReferenceOutsideItsObjectIsSkipped(): void
    {
        $schema = $this->translator()->translate([
            'b'         => 'string',
            'c'         => 'required_with:missing',
            'd'         => 'required_with_all:b,missing',
            'user.a'    => ['required_with:b', 'required_without:user.missing'],
            'user.p'    => ['string', 'confirmed:repeat'],
            'items.*.a' => 'required_with:b',
        ]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'b'     => ['type' => 'string'],
                'c'     => [],
                'd'     => [],
                'user'  => ['type' => 'object', 'properties' => ['a' => [], 'p' => ['type' => 'string']]],
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => []]]],
            ],
            'required' => ['user'],
        ], $schema);
    }

    /**
     * Test that a top-level rules key PHP casts to an integer documents an
     * object property named by the index, required by its string name.
     *
     * @return void
     */
    public function testNumericTopLevelKeyIsAnObjectProperty(): void
    {
        $schema = $this->translator()->translate(['0' => ['required', 'string'], '1' => 'integer']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [0 => ['type' => 'string'], 1 => ['type' => 'integer']],
            'required'   => ['0'],
        ], $schema);
    }

    /**
     * Provide array fields whose rules constrain an element by its list index.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function indexedArrayProvider(): iterable
    {
        yield 'array' => [
            ['tags' => ['required', 'array'], 'tags.0' => ['required', 'string']],
            ['type' => 'array', 'minItems' => 1],
        ];

        yield 'nullable array' => [
            ['tags' => ['nullable', 'array', 'max:3'], 'tags.0' => 'string', 'tags.1.name' => 'string'],
            ['type' => ['array', 'null'], 'maxItems' => 3],
        ];

        yield 'array with items' => [
            ['tags' => 'array', 'tags.*' => 'string', 'tags.0' => ['required', 'string', 'max:5']],
            ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        yield 'array with named keys' => [
            ['tags' => 'array', 'tags.0' => 'string', 'tags.name' => ['required', 'string']],
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
        ];
    }

    /**
     * Test that a child keying an array field by a list index is left out of
     * the array's schema, so a list the rules accept is never documented as an
     * object.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('indexedArrayProvider')]
    public function testIndexChildOfAnArrayFieldIsLeftOut(array $rules, array $expected): void
    {
        self::assertSame($expected, $this->translator()->translate($rules)['properties']['tags']);
    }

    /**
     * Test that an index child of a field declaring no array type documents an
     * object property named by the index, required by its string name.
     *
     * @return void
     */
    public function testIndexChildOfAnUntypedFieldIsAnObjectProperty(): void
    {
        $schema = $this->translator()->translate(['meta' => 'required', 'meta.0' => ['required', 'string'], 'meta.1' => 'integer']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'meta' => [
                    'type'       => 'object',
                    'properties' => [0 => ['type' => 'string'], 1 => ['type' => 'integer']],
                    'required'   => ['0'],
                ],
            ],
            'required' => ['meta'],
        ], $schema);
    }

    /**
     * Provide array fields addressed by named children or restricted to named
     * keys, each with the schema it documents.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function keyedArrayProvider(): iterable
    {
        yield 'optional named child' => [
            ['tags' => 'array', 'tags.name' => 'sometimes|string'],
            ['type' => ['object', 'array'], 'properties' => ['name' => ['type' => 'string']]],
        ];

        yield 'nullable' => [
            ['tags' => 'nullable|array', 'tags.name' => 'string'],
            ['type' => ['object', 'array', 'null'], 'properties' => ['name' => ['type' => 'string']]],
        ];

        yield 'sized' => [
            ['tags' => 'array|between:1,3', 'tags.name' => 'string'],
            [
                'type'          => ['object', 'array'],
                'minItems'      => 1,
                'maxItems'      => 3,
                'properties'    => ['name' => ['type' => 'string']],
                'minProperties' => 1,
                'maxProperties' => 3,
            ],
        ];

        yield 'required named child' => [
            ['tags' => 'nullable|array|in:a|min:1', 'tags.name' => 'required|string'],
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'], 'minProperties' => 1],
        ];

        yield 'list' => [
            ['tags' => 'list', 'tags.name' => 'required|string', 'tags.0' => 'string'],
            [],
        ];

        yield 'list with items' => [
            ['tags' => 'list', 'tags.*' => 'string', 'tags.name' => 'integer'],
            ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        yield 'array keys' => [
            ['tags' => 'array:name,email'],
            ['type' => ['object', 'array'], 'properties' => ['name' => [], 'email' => []], 'additionalProperties' => false, 'maxItems' => 0],
        ];

        yield 'array keys with children' => [
            ['tags' => ['array', 'array:name,"e,mail"'], 'tags.name' => 'string', 'tags.extra' => 'string'],
            [
                'type'                 => ['object', 'array'],
                'properties'           => ['name' => ['type' => 'string'], 'extra' => false, 'e,mail' => []],
                'additionalProperties' => false,
                'maxItems'             => 0,
            ],
        ];

        yield 'array keys left empty' => [
            ['tags' => 'array:'],
            ['type' => ['object', 'array'], 'properties' => ['' => []], 'additionalProperties' => false, 'maxItems' => 0],
        ];

        yield 'array keys naming indices' => [
            ['tags' => 'array:0,1,x|max:5'],
            [
                'type'                 => ['object', 'array'],
                'maxItems'             => 2,
                'properties'           => [0 => [], 1 => [], 'x' => []],
                'additionalProperties' => false,
                'maxProperties'        => 5,
            ],
        ];

        yield 'array keys under a lower size' => [
            ['tags' => 'array:0,1|max:1'],
            [
                'type'                 => ['object', 'array'],
                'maxItems'             => 1,
                'properties'           => [0 => [], 1 => []],
                'additionalProperties' => false,
                'maxProperties'        => 1,
            ],
        ];

        yield 'array keys with a required child' => [
            ['tags' => 'array:name', 'tags.name' => 'required|string'],
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'], 'additionalProperties' => false],
        ];
    }

    /**
     * Test that an array field addressed by named children or restricted to
     * named keys documents the object it may be and the list it may be sent as.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('keyedArrayProvider')]
    public function testKeyedArrayFieldDocumentsItsObjectAndListForms(array $rules, array $expected): void
    {
        self::assertSame($expected, $this->translator()->translate($rules)['properties']['tags']);
    }

    /**
     * Provide array fields addressed by named children or restricted to named
     * keys, each with JSON request bodies the validator accepts and refuses.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function keyedArrayBodies(): iterable
    {
        yield 'optional named child' => [
            ['tags' => 'array', 'tags.name' => 'sometimes|string'],
            ['{"tags":["a"]}', '{"tags":[]}', '{"tags":{}}', '{"tags":{"name":"x"}}', '{"tags":{"name":1}}', '{"tags":{"other":1}}', '{"tags":"a"}'],
        ];

        yield 'nullable' => [
            ['tags' => 'nullable|array', 'tags.name' => 'string'],
            ['{"tags":null}', '{"tags":["a"]}', '{"tags":{"name":"x"}}', '{"tags":{"name":null}}'],
        ];

        yield 'sized' => [
            ['tags' => 'array|min:2|max:3', 'tags.name' => 'sometimes|string'],
            ['{"tags":{"name":"x"}}', '{"tags":{"name":"x","a":1}}', '{"tags":{"a":1,"b":2,"c":3,"d":4}}', '{"tags":["a"]}', '{"tags":["a","b"]}', '{"tags":["a","b","c","d"]}'],
        ];

        yield 'required named child' => [
            ['tags' => 'nullable|array', 'tags.name' => 'required|string'],
            ['{"tags":null}', '{"tags":[]}', '{"tags":["a"]}', '{"tags":{"name":"x"}}', '{"tags":{"other":"x"}}'],
        ];

        yield 'required with a named sibling' => [
            ['tags' => 'array', 'tags.a' => 'required_with:tags.b|string', 'tags.b' => 'string'],
            ['{"tags":["x"]}', '{"tags":{"b":"y"}}', '{"tags":{"a":"x","b":"y"}}'],
        ];

        yield 'confirmed' => [
            ['tags' => 'array|confirmed', 'tags.name' => 'sometimes|string'],
            ['{"tags":{"name":"x"},"tags_confirmation":{"name":"x"}}', '{"tags":["a"],"tags_confirmation":["a"]}', '{"tags":["a"]}'],
        ];

        yield 'list' => [
            ['tags' => 'list', 'tags.name' => 'sometimes|string', 'tags.0' => 'string'],
            ['{"tags":["a"]}', '{"tags":[]}', '{"tags":{}}', '{"tags":{"0":"a"}}'],
        ];

        yield 'array keys' => [
            ['tags' => 'array:name,email', 'tags.name' => 'sometimes|string', 'tags.extra' => 'sometimes|string'],
            ['{"tags":[]}', '{"tags":["a"]}', '{"tags":{"name":"x"}}', '{"tags":{"email":1}}', '{"tags":{"name":1}}', '{"tags":{"zzz":"x"}}', '{"tags":{"extra":"x"}}'],
        ];

        yield 'array keys naming indices' => [
            ['tags' => 'array:0,1,x'],
            ['{"tags":["a"]}', '{"tags":["a","b"]}', '{"tags":["a","b","c"]}', '{"tags":{"x":1}}', '{"tags":{"y":1}}'],
        ];
    }

    /**
     * Test that the request schema accepts exactly the JSON bodies the
     * validator accepts for an array field addressed by named children or
     * restricted to named keys, whether each is sent as an object or a list.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $bodies
     * @return void
     */
    #[DataProvider('keyedArrayBodies')]
    public function testKeyedArrayFieldAcceptsWhatTheValidatorAccepts(array $rules, array $bodies): void
    {
        $schema = $this->translator()->translate($rules);

        foreach ($bodies as $body) {
            self::assertSame(
                Validator::make((array) json_decode($body, true, 512, JSON_THROW_ON_ERROR), $rules)->passes(),
                $this->isPayloadAcceptedBySchema($schema, $body),
                sprintf('The schema and the validator disagree on %s.', $body),
            );
        }
    }

    /**
     * Provide rules whose fields refuse an empty value or require a field held
     * by their parent, each with the schema of the field they shape.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}>
     */
    public static function requiredPresenceProvider(): iterable
    {
        yield 'required keyed array' => [
            ['tags' => 'required|array', 'tags.name' => 'sometimes|string'],
            'tags',
            ['type' => ['object', 'array'], 'minItems' => 1, 'properties' => ['name' => ['type' => 'string']], 'minProperties' => 1],
        ];

        yield 'filled keyed array above one' => [
            ['tags' => 'filled|array|min:2', 'tags.name' => 'sometimes|string'],
            'tags',
            ['type' => ['object', 'array'], 'minItems' => 2, 'properties' => ['name' => ['type' => 'string']], 'minProperties' => 2],
        ];

        yield 'required untyped parent' => [
            ['meta' => 'required', 'meta.x' => 'sometimes|string'],
            'meta',
            ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'minProperties' => 1],
        ];

        yield 'nullable untyped parent' => [
            ['meta' => 'nullable', 'meta.x' => 'sometimes|string'],
            'meta',
            ['type' => ['object', 'null'], 'properties' => ['x' => ['type' => 'string']]],
        ];

        yield 'required nullable untyped parent' => [
            ['meta' => 'required|nullable', 'meta.x' => 'sometimes|string'],
            'meta',
            ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'minProperties' => 1],
        ];

        yield 'nullable untyped parent with a required child' => [
            ['meta' => 'nullable', 'meta.x' => 'required|string'],
            'meta',
            ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'required' => ['x']],
        ];

        yield 'nullable array with a child required without its sibling' => [
            ['address' => 'nullable|array', 'address.city' => 'required_without:address.zip', 'address.zip' => 'string'],
            'address',
            ['type' => 'object', 'properties' => ['city' => [], 'zip' => ['type' => 'string']], 'anyOf' => [['required' => ['city']], ['required' => ['zip']]]],
        ];

        yield 'required array holding a required child' => [
            ['address' => 'required|array', 'address.city' => 'required'],
            'address',
            ['type' => 'object', 'properties' => ['city' => []], 'required' => ['city'], 'minProperties' => 1],
        ];

        yield 'nullable array with a required index' => [
            ['tags' => 'nullable|array', 'tags.0' => 'required|string', 'tags.name' => 'sometimes|string'],
            'tags',
            ['type' => ['object', 'array'], 'properties' => ['name' => ['type' => 'string']]],
        ];

        yield 'nullable wildcard element holding a required field' => [
            ['items' => 'array', 'items.*' => 'nullable', 'items.*.name' => 'required'],
            'items',
            ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => []], 'required' => ['name']]],
        ];

        yield 'deeply nested required field' => [
            ['a' => 'nullable', 'a.b' => 'sometimes|nullable', 'a.b.c' => 'required'],
            'a',
            ['type' => 'object', 'properties' => ['b' => ['type' => 'object', 'properties' => ['c' => []], 'required' => ['c']]], 'required' => ['b']],
        ];

        yield 'array with a required index and a required named child' => [
            ['tags' => 'array', 'tags.0' => 'required', 'tags.name' => 'required'],
            'tags',
            ['type' => 'object', 'properties' => ['name' => []], 'required' => ['name']],
        ];

        yield 'array with a named child required and holding one required without the array' => [
            ['tags' => 'array', 'tags.name' => 'required', 'tags.name.sub' => 'required_without:tags'],
            'tags',
            ['type' => 'object', 'properties' => ['name' => ['type' => 'object', 'properties' => ['sub' => []], 'minProperties' => 1]], 'required' => ['name']],
        ];

        yield 'array with a child required without the array' => [
            ['address' => 'nullable|array', 'address.city' => 'required_without:address'],
            'address',
            ['type' => ['object', 'array'], 'properties' => ['city' => []]],
        ];

        yield 'required array beneath an excludable parent' => [
            ['meta' => 'exclude|array', 'meta.tags' => 'required|nullable|array', 'meta.x.y' => 'required'],
            'meta',
            ['type' => ['object', 'array'], 'properties' => ['tags' => ['type' => ['array', 'null']], 'x' => ['type' => 'object', 'properties' => ['y' => []]]]],
        ];

        yield 'excludable parent' => [
            ['a' => 'string', 'meta' => 'exclude_if:a,x|nullable', 'meta.x' => 'required'],
            'meta',
            ['type' => ['object', 'null'], 'properties' => ['x' => []]],
        ];
    }

    /**
     * Test that a field refusing an empty value documents a non-empty array or
     * object and no null, and a parent whose fields demand it be sent refuses
     * null and any list holding none of them.
     *
     * @param  array<string, mixed>  $rules
     * @param  string  $field
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('requiredPresenceProvider')]
    public function testRequiredPresenceShapesTheFieldSchema(array $rules, string $field, array $expected): void
    {
        self::assertSame($expected, $this->translator()->translate($rules)['properties'][$field]);
    }

    /**
     * Provide rules whose fields require a field held by their parent, each
     * with the fields the top-level object requires.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function requiredParentProvider(): iterable
    {
        yield 'required child' => [['address' => 'array', 'address.city' => 'required'], ['address']];
        yield 'undeclared parent' => [['address.city' => 'required'], ['address']];
        yield 'sometimes parent' => [['address' => 'sometimes|array', 'address.city' => 'required'], ['address']];
        yield 'nullable parent' => [['address' => 'nullable|array', 'address.city' => 'required'], ['address']];
        yield 'list parent' => [['tags' => 'list', 'tags.name' => 'required'], ['tags']];
        yield 'required index' => [['tags' => 'array', 'tags.0' => 'required'], ['tags']];
        yield 'deep child' => [['p.q.r' => 'required'], ['p']];
        yield 'deep child beneath a sometimes node' => [['p' => 'array', 'p.q' => 'sometimes|array', 'p.q.r' => 'required'], ['p']];
        yield 'present child' => [['address.city' => 'present'], ['address']];
        yield 'required without within' => [['address.city' => 'required_without:address.zip', 'address.zip' => 'string'], ['address']];
        yield 'required without the parent' => [['address.city' => 'required_without:address'], ['address']];
        yield 'required without any within' => [['other' => 'string', 'address.city' => 'required_without:other,address.zip'], ['address']];
        yield 'required without all within' => [['address.city' => 'required_without_all:address.zip,address.st'], ['address']];
        yield 'sometimes child' => [['address' => 'array', 'address.city' => 'sometimes|required'], []];
        yield 'excludable child' => [['a' => 'string', 'address.city' => 'exclude_if:a,x|required'], []];
        yield 'excludable parent' => [['a' => 'string', 'address' => 'exclude_if:a,x|array', 'address.city' => 'required'], []];
        yield 'excludable middle' => [['a' => 'string', 'x.y' => 'exclude_if:a,x|array', 'x.y.z' => 'required'], []];
        yield 'excludable grandparent' => [['p' => 'exclude|array', 'p.q.r' => 'required'], []];
        yield 'required parent of an excludable child' => [['p' => 'required|array', 'p.q' => 'exclude_if:x,y|required'], ['p']];
        yield 'wildcard parent' => [['items' => 'array', 'items.*.name' => 'required'], []];
        yield 'wildcard middle' => [['w.*.b.c' => 'required'], []];
        yield 'required with' => [['address.city' => 'required_with:address.zip', 'address.zip' => 'string'], []];
        yield 'required with all' => [['address.city' => 'required_with_all:address.zip'], []];
        yield 'required without outside' => [['other' => 'string', 'address.city' => 'required_without:other'], []];
        yield 'required without all mixed' => [['other' => 'string', 'address.city' => 'required_without_all:other,address.zip'], []];
        yield 'prohibited parent' => [['tags' => 'prohibited', 'tags.name' => 'required'], []];
    }

    /**
     * Test that a parent is required exactly when one of its fields refuses
     * every body missing the parent.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $required
     * @return void
     */
    #[DataProvider('requiredParentProvider')]
    public function testParentIsRequiredWhenItsFieldsRefuseItMissing(array $rules, array $required): void
    {
        self::assertSame($required, $this->translator()->translate($rules)['required'] ?? []);
    }

    /**
     * Provide rules whose fields refuse an empty value or require a field held
     * by their parent, each with JSON request bodies the validator accepts and
     * refuses.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function requiredPresenceBodies(): iterable
    {
        yield 'required array' => [
            ['tags' => 'required|array'],
            ['{}', '{"tags":[]}', '{"tags":{}}', '{"tags":null}', '{"tags":["a"]}'],
        ];

        yield 'required nullable array' => [
            ['tags' => 'nullable|required|array'],
            ['{"tags":[]}', '{"tags":null}', '{"tags":["a"]}'],
        ];

        yield 'present array' => [
            ['tags' => 'present|array'],
            ['{}', '{"tags":[]}', '{"tags":null}', '{"tags":["a"]}'],
        ];

        yield 'present child' => [
            ['address' => 'array', 'address.city' => 'present'],
            ['{}', '{"address":{}}', '{"address":{"city":null}}'],
        ];

        yield 'filled array' => [
            ['tags' => 'filled|array'],
            ['{}', '{"tags":[]}', '{"tags":null}', '{"tags":["a"]}'],
        ];

        yield 'filled nullable array' => [
            ['tags' => 'nullable|filled|array'],
            ['{}', '{"tags":null}', '{"tags":[]}'],
        ];

        yield 'sometimes required array' => [
            ['tags' => 'sometimes|required|array'],
            ['{}', '{"tags":[]}', '{"tags":["a"]}'],
        ];

        yield 'required sized arrays' => [
            ['a' => 'required|array|size:0', 'b' => 'required|array|between:0,2', 'c' => 'sometimes|required|array|min:2'],
            ['{"a":[],"b":["x"]}', '{"a":["x"],"b":["x"]}', '{"a":["x"],"b":[]}', '{"a":["x"],"b":["x"],"c":["x"]}'],
        ];

        yield 'excluded required array' => [
            ['a' => 'string', 'tags' => 'exclude_if:a,x|required|array'],
            ['{"a":"x"}', '{"a":"x","tags":[]}', '{"a":"y","tags":["z"]}'],
        ];

        yield 'required nullable string' => [
            ['s' => 'required|nullable|string', 't' => 'sometimes|filled|nullable|string'],
            ['{"s":null}', '{"s":"x"}', '{"s":"x","t":null}', '{"s":"x","t":"y"}'],
        ];

        yield 'required keyed array' => [
            ['tags' => 'required|array', 'tags.name' => 'sometimes|string'],
            ['{}', '{"tags":[]}', '{"tags":{}}', '{"tags":["a"]}', '{"tags":{"name":"x"}}', '{"tags":{"other":1}}'],
        ];

        yield 'required untyped parent' => [
            ['meta' => 'required', 'meta.x' => 'sometimes|string'],
            ['{"meta":{}}', '{"meta":[]}', '{"meta":{"y":1}}', '{"meta":{"x":"a"}}', '{"meta":null}'],
        ];

        yield 'required child' => [
            ['address' => 'array', 'address.city' => 'required'],
            ['{}', '{"address":{}}', '{"address":[]}', '{"address":["x"]}', '{"address":null}', '{"address":{"city":"x"}}'],
        ];

        yield 'sometimes parent' => [
            ['address' => 'sometimes|array', 'address.city' => 'required'],
            ['{}', '{"address":{"city":"x"}}'],
        ];

        yield 'nullable parent' => [
            ['address' => 'nullable|array', 'address.city' => 'required'],
            ['{}', '{"address":null}', '{"address":{"city":"x"}}'],
        ];

        yield 'sometimes child' => [
            ['address' => 'array', 'address.city' => 'sometimes|required'],
            ['{}', '{"address":{}}', '{"address":{"city":"x"}}'],
        ];

        yield 'excluded child' => [
            ['a' => 'string', 'address' => 'array', 'address.city' => 'exclude_if:a,x|required'],
            ['{"a":"x"}', '{"a":"y","address":{"city":"z"}}'],
        ];

        yield 'excluded parent' => [
            ['a' => 'string', 'address' => 'exclude_if:a,x|array', 'address.city' => 'required'],
            ['{"a":"x"}', '{"a":"y","address":{"city":"z"}}'],
        ];

        yield 'excluded grandparent' => [
            ['a' => 'exclude|array', 'a.b' => 'required|array', 'a.c' => 'array', 'a.c.d' => 'required'],
            ['{"a":{}}', '{"a":{"b":[]}}', '{"a":{"c":{}}}', '{}'],
        ];

        yield 'excluded element' => [
            ['a' => 'array', 'a.0' => 'exclude', 'a.*.b' => 'filled|array', 'c' => 'array', 'c.0' => 'exclude', 'c.*' => 'filled|array'],
            ['{"a":[{"b":[]}]}', '{"a":[]}', '{"c":[[]]}', '{}'],
        ];

        yield 'excluded by a rule object' => [
            ['a' => [Rule::excludeIf(true), 'array'], 'a.b' => 'required', 'c' => [Rule::excludeUnless(false)], 'c.d' => 'required'],
            ['{}', '{"a":{}}'],
        ];

        yield 'required without the parent or its index' => [
            ['a' => 'nullable|array', 'a.city' => 'required_without:a', 'b' => 'array', 'b.city' => 'required_without_all:b.0,b.zip', 'b.zip' => 'string'],
            ['{"a":["x"],"b":["x"]}', '{"a":{"city":"x"},"b":{"zip":"y"}}', '{"a":null,"b":["x"]}', '{"a":["x"]}'],
        ];

        yield 'required beneath an index' => [
            ['a' => 'array', 'a.0.b' => 'required', 'a.name' => 'sometimes|string'],
            ['{"a":[{"b":1}]}', '{}'],
        ];

        yield 'excluded nullable parent' => [
            ['a' => 'string', 'meta' => 'exclude_if:a,x|nullable', 'meta.x' => 'required'],
            ['{"a":"x","meta":null}', '{"a":"y","meta":{"x":1}}'],
        ];

        yield 'wildcard parent' => [
            ['items' => 'array', 'items.*.name' => 'required'],
            ['{}', '{"items":[]}', '{"items":[{}]}', '{"items":[{"name":"x"}]}'],
        ];

        yield 'nullable wildcard element' => [
            ['items' => 'array', 'items.*' => 'nullable', 'items.*.name' => 'required'],
            ['{"items":[null]}', '{"items":[{"name":"x"}]}'],
        ];

        yield 'deep' => [
            ['a' => 'nullable', 'a.b' => 'sometimes|nullable', 'a.b.c' => 'required'],
            ['{}', '{"a":null}', '{"a":{}}', '{"a":{"b":null}}', '{"a":{"b":{}}}', '{"a":{"b":{"c":1}}}'],
        ];

        yield 'required with' => [
            ['address' => 'array', 'address.city' => 'required_with:address.zip', 'address.zip' => 'string'],
            ['{}', '{"address":{"zip":"x"}}', '{"address":{"city":"x","zip":"y"}}'],
        ];

        yield 'required without' => [
            ['address' => 'nullable|array', 'address.city' => 'required_without:address.zip', 'address.zip' => 'string'],
            ['{}', '{"address":null}', '{"address":["x"]}', '{"address":{}}', '{"address":{"zip":"x"}}', '{"address":{"city":"x"}}'],
        ];

        yield 'required without outside' => [
            ['other' => 'string', 'address' => 'array', 'address.city' => 'required_without:other'],
            ['{"other":"x"}', '{"other":"x","address":{}}'],
        ];

        yield 'required without any' => [
            ['other' => 'string', 'address' => 'array', 'address.city' => 'required_without:other,address.zip', 'address.zip' => 'string'],
            ['{"other":"x"}', '{"other":"x","address":{"zip":"y"}}'],
        ];

        yield 'required without all' => [
            ['address' => 'array', 'address.city' => 'required_without_all:address.zip,address.st', 'address.zip' => 'string', 'address.st' => 'string'],
            ['{}', '{"address":{"zip":"x"}}', '{"address":{}}'],
        ];

        yield 'nullable untyped parent' => [
            ['meta' => 'nullable', 'meta.x' => 'sometimes|string'],
            ['{}', '{"meta":null}', '{"meta":{}}', '{"meta":{"x":"a"}}', '{"meta":{"x":1}}'],
        ];

        yield 'nullable untyped parent with a required child' => [
            ['meta' => 'nullable', 'meta.x' => 'required|string'],
            ['{}', '{"meta":null}', '{"meta":{}}', '{"meta":{"x":"a"}}'],
        ];

        yield 'required index' => [
            ['tags' => 'nullable|array', 'tags.0' => 'required|string'],
            ['{}', '{"tags":null}', '{"tags":["a"]}'],
        ];
    }

    /**
     * Test that the request schema accepts exactly the JSON bodies the
     * validator accepts when fields refuse an empty value or require a field
     * held by their parent.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $bodies
     * @return void
     */
    #[DataProvider('requiredPresenceBodies')]
    public function testRequiredPresenceAcceptsWhatTheValidatorAccepts(array $rules, array $bodies): void
    {
        $schema = $this->translator()->translate($rules);

        foreach ($bodies as $body) {
            self::assertSame(
                Validator::make((array) json_decode($body, true, 512, JSON_THROW_ON_ERROR), $rules)->passes(),
                $this->isPayloadAcceptedBySchema($schema, $body),
                sprintf('The schema and the validator disagree on %s.', $body),
            );
        }
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

    /**
     * Determine whether a translated schema accepts a request body.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $body
     * @return bool
     */
    private function isAcceptedBySchema(array $schema, array $body): bool
    {
        return $this->isPayloadAcceptedBySchema($schema, json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    /**
     * Determine whether a translated schema accepts a JSON request body, read
     * as sent, so an empty object and an empty list stay distinct.
     *
     * @param  array<string, mixed>  $schema
     * @param  string  $payload
     * @return bool
     */
    private function isPayloadAcceptedBySchema(array $schema, string $payload): bool
    {
        $document = (new SchemaObjectNormaliser)->normalise(['schema' => ['$schema' => 'https://json-schema.org/draft/2020-12/schema', ...$schema]]);
        $compiled = Helper::toJSON($document['schema']);

        self::assertIsObject($compiled);

        return (new SchemaValidator)->validate(json_decode($payload, false, 512, JSON_THROW_ON_ERROR), $compiled)->isValid();
    }
}
