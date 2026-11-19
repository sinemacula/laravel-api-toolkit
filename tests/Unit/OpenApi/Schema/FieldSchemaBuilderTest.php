<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use Tests\Fixtures\Enums\UserStatus;
use Tests\TestCase;

/**
 * Tests for the field-schema builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldSchemaBuilder::class)]
final class FieldSchemaBuilderTest extends TestCase
{
    /**
     * Provide the comparison and digit rules with the bounds they export.
     *
     * @return iterable<string, array{0: list<string>, 1: array<string, mixed>}>
     */
    public static function boundsProvider(): iterable
    {
        yield from [
            'exclusive and inclusive' => [['numeric', 'gt:0', 'lte:100'], ['type' => 'number', 'maximum' => 100, 'exclusiveMinimum' => 0]],
            'tightest lower'          => [['integer', 'min:1', 'gte:5'], ['type' => 'integer', 'minimum' => 5]],
            'string comparison'       => [['string', 'gt:3', 'gte:1', 'lt:9', 'lte:8'], ['type' => 'string']],
            'multiple of fraction'    => [['numeric', 'multiple_of:0.5'], ['type' => 'number', 'multipleOf' => 0.5]],
            'multiple of zero'        => [['integer', 'multiple_of:0'], ['type' => 'integer']],
            'untyped multiple of'     => [['multiple_of:2'], []],
            'integer digits'          => [['integer', 'digits:4'], ['type' => 'integer', 'minimum' => 1000, 'maximum' => 9999]],
            'twenty digits'           => [['integer', 'digits:20'], ['type' => 'integer']],
            'string digits'           => [['digits:4'], ['pattern' => '^[0-9]{4}$']],
        ];
    }

    /**
     * Test that the comparison and digit rules export the bounds they apply.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('boundsProvider')]
    public function testComparisonAndDigitRulesBoundTheField(array $tokens, array $expected): void
    {
        self::assertSame($expected, (new FieldSchemaBuilder)->build($tokens)['schema']);
    }

    /**
     * Provide the value-shaping rules with the keywords they export.
     *
     * @return iterable<string, array{0: list<string>, 1: array<string, mixed>}>
     */
    public static function valueRuleProvider(): iterable
    {
        yield from [
            'accepted'              => [['accepted'], ['enum' => ['yes', 'on', '1', 1, true, 'true']]],
            'boolean accepted'      => [['boolean', 'accepted'], ['type' => 'boolean', 'enum' => [true]]],
            'string accepted'       => [['string', 'accepted'], ['type' => 'string', 'enum' => ['yes', 'on', '1', 'true']]],
            'integer accepted'      => [['integer', 'accepted'], ['type' => 'integer', 'enum' => [1]]],
            'number accepted'       => [['numeric', 'accepted'], ['type' => 'number', 'enum' => [1]]],
            'array accepted'        => [['array', 'accepted'], ['type' => 'array', 'enum' => [], 'minItems' => 1]],
            'nullable accepted'     => [['nullable', 'accepted'], ['enum' => ['yes', 'on', '1', 1, true, 'true']]],
            'declined'              => [['declined'], ['enum' => ['no', 'off', '0', 0, false, 'false']]],
            'boolean declined'      => [['boolean', 'declined'], ['type' => 'boolean', 'enum' => [false]]],
            'in and accepted'       => [['in:no,maybe,yes', 'accepted'], ['enum' => ['yes']]],
            'accepted and declined' => [['accepted', 'declined'], ['enum' => []]],
            'integer not in'        => [['integer', 'not_in:1,2'], ['type' => 'integer', 'not' => ['enum' => [1, 2]]]],
            'untyped not in'        => [['not_in:"a","b,c"'], ['not' => ['enum' => ['a', 'b,c']]]],
            'array not in'          => [['array', 'not_in:a'], ['type' => 'array']],
            'empty not in'          => [['string', 'not_in:'], ['type' => 'string']],
            'integer not in zero'   => [['integer', 'not_in:0'], ['type' => 'integer']],
            'starts with'           => [['starts_with:a.b,c'], ['pattern' => '^(?:a\.b|c)']],
            'alpha ascii'           => [['alpha:ascii'], ['pattern' => '^[a-zA-Z]+$']],
            'json'                  => [['json'], ['contentMediaType' => 'application/json']],
            'string json'           => [['string', 'json'], ['type' => 'string', 'contentMediaType' => 'application/json']],
        ];
    }

    /**
     * Test that the value-shaping rules export the keywords they apply.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('valueRuleProvider')]
    public function testValueRulesShapeTheField(array $tokens, array $expected): void
    {
        self::assertSame($expected, (new FieldSchemaBuilder)->build($tokens)['schema']);
    }

    /**
     * Provide the rule-token to schema-fragment mapping table.
     *
     * @return iterable<string, array{0: list<string>, 1: array<string, mixed>}>
     */
    public static function mappingProvider(): iterable
    {
        yield from [
            'string with max'      => [['required', 'string', 'max:255'], ['type' => 'string', 'maxLength' => 255]],
            'nullable integer'     => [['nullable', 'integer'], ['type' => ['integer', 'null']]],
            'integer bounds'       => [['integer', 'min:1', 'max:5'], ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]],
            'numeric between'      => [['numeric', 'between:1.5,9'], ['type' => 'number', 'minimum' => 1.5, 'maximum' => 9]],
            'array max items'      => [['array', 'max:3'], ['type' => 'array', 'maxItems' => 3]],
            'string exact size'    => [['string', 'size:4'], ['type' => 'string', 'minLength' => 4, 'maxLength' => 4]],
            'boolean'              => [['boolean'], ['type' => 'boolean']],
            'email format'         => [['string', 'email'], ['type' => 'string', 'format' => 'email']],
            'uuid format'          => [['uuid'], ['type' => 'string', 'format' => 'uuid']],
            'ulid format'          => [['ulid'], ['type' => 'string', 'format' => 'ulid']],
            'url format'           => [['url'], ['type' => 'string', 'format' => 'uri']],
            'ip either family'     => [['ip'], ['type' => 'string', 'anyOf' => [['format' => 'ipv4'], ['format' => 'ipv6']]]],
            'ipv4 format'          => [['ipv4'], ['type' => 'string', 'format' => 'ipv4']],
            'ipv6 format'          => [['ipv6'], ['type' => 'string', 'format' => 'ipv6']],
            'date any string'      => [['date'], ['type' => 'string']],
            'regex pattern'        => [['regex:/^a$/'], ['pattern' => '^a$']],
            'in enum'              => [['in:a,b,c'], ['enum' => ['a', 'b', 'c']]],
            'typed enum'           => [['string', 'in:a,b,c'], ['type' => 'string', 'enum' => ['a', 'b', 'c']]],
            'quoted in enum'       => [['in:"a","b"'], ['enum' => ['a', 'b']]],
            'in quoted comma'      => [['in:"a,b",c'], ['enum' => ['a,b', 'c']]],
            'in doubled quote'     => [['in:"say ""hi""",x'], ['enum' => ['say "hi"', 'x']]],
            'in empty value'       => [['in:a,,b'], ['enum' => ['a', '', 'b']]],
            'integer in enum'      => [['integer', 'in:"1","2"'], ['type' => 'integer', 'enum' => [1, 2]]],
            'number in enum'       => [['numeric', 'in:1.5,2'], ['type' => 'number', 'enum' => [1.5, 2]]],
            'integer in non-num'   => [['integer', 'in:1,a'], ['type' => 'integer', 'enum' => [1, 'a']]],
            'number in exponent'   => [['numeric', 'in:1e-2,1.0e999'], ['type' => 'number', 'enum' => ['1e-2', '1.0e999']]],
            'number in trailing 0' => [['numeric', 'in:1.0,0.5'], ['type' => 'number', 'enum' => ['1.0', 0.5]]],
            'integer in padded'    => [['integer', 'in:01,-0,-3'], ['type' => 'integer', 'enum' => ['01', '-0', -3]]],
            'array in items enum'  => [['array', 'in:a,1,1.0'], ['type' => 'array', 'items' => ['enum' => ['a', '1', 1, '1.0']]]],
            'nullable array in'    => [['nullable', 'array', 'in:a'], ['type' => ['array', 'null'], 'items' => ['enum' => ['a']]]],
            'string numeric in'    => [['string', 'in:1,2'], ['type' => 'string', 'enum' => ['1', '2']]],
            'untyped numeric in'   => [['in:1,2'], ['enum' => ['1', '2']]],
            'boolean numeric in'   => [['boolean', 'in:0,1'], ['type' => 'boolean', 'enum' => ['0', '1']]],
            'nullable integer in'  => [['nullable', 'integer', 'in:1,2'], ['type' => ['integer', 'null'], 'enum' => [1, 2, null]]],
            'exact enum values'    => [['integer', 'enum-values:[2,3]'], ['type' => 'integer', 'enum' => [2, 3]]],
            'exact verbatim'       => [['string', 'enum-values:["a,b","dir\\\"]'], ['type' => 'string', 'enum' => ['a,b', 'dir\\']]],
            'exact string values'  => [['string', 'enum-values:[1,"a"]'], ['type' => 'string', 'enum' => ['1', 'a']]],
            'untyped exact values' => [['enum-values:[1,"a"]'], ['enum' => [1, 'a']]],
            'exact empty values'   => [['string', 'enum-values:[]'], ['type' => 'string', 'enum' => []]],
            'nullable exact empty' => [['nullable', 'string', 'enum-values:[]'], ['type' => ['string', 'null'], 'enum' => [null]]],
            'exact values over in' => [['string', 'in:a', 'enum-values:["b"]'], ['type' => 'string', 'enum' => ['b']]],
            'malformed exact skip' => [['string', 'in:a', 'enum-values:{"x":1}'], ['type' => 'string', 'enum' => ['a']]],
            'enum class ref'       => [['enum-class:' . UserStatus::class], ['$ref' => '#/components/schemas/UserStatus']],
            'nullable enum class'  => [['nullable', 'enum-class:' . UserStatus::class], ['anyOf' => [['$ref' => '#/components/schemas/UserStatus'], ['type' => 'null']]]],
            'string between'       => [['string', 'between:5,50'], ['type' => 'string', 'minLength' => 5, 'maxLength' => 50]],
            'integer exact size'   => [['integer', 'size:4'], ['type' => 'integer', 'minimum' => 4, 'maximum' => 4]],
            'array between items'  => [['array', 'between:1,3'], ['type' => 'array', 'minItems' => 1, 'maxItems' => 3]],
            'nullable email'       => [['nullable', 'string', 'email'], ['type' => ['string', 'null'], 'format' => 'email']],
            'nullable enum'        => [['nullable', 'in:a,b'], ['enum' => ['a', 'b', null]]],
            'nullable typed enum'  => [['nullable', 'string', 'in:a,b'], ['type' => ['string', 'null'], 'enum' => ['a', 'b', null]]],
            'nullable date'        => [['nullable', 'date'], ['type' => ['string', 'null']]],
            'file binary'          => [['file'], ['type' => 'string', 'format' => 'binary']],
            'image binary'         => [['image'], ['type' => 'string', 'format' => 'binary']],
            'file size skipped'    => [['file', 'max:2048'], ['type' => 'string', 'format' => 'binary']],
            'image size skipped'   => [['image', 'min:1'], ['type' => 'string', 'format' => 'binary']],
            'unknown rule skipped' => [['string', 'foobar:foo'], ['type' => 'string']],
            'boolean size skipped' => [['boolean', 'min:1'], ['type' => 'boolean']],
            'array keys arguments' => [['array:name,email'], ['type' => 'array']],
            'regex two char pairs' => [['regex://'], ['pattern' => '']],
            'regex dotall newline' => [["regex:/a\nb/"], ['pattern' => "a\nb"]],
            'regex caret anchored' => [['regex:a/b/'], ['pattern' => 'a/b/']],
            'regex dollar trailer' => [['regex:/abc/;'], ['pattern' => '/abc/;']],
            'regex colon argument' => [['regex:/^a:b$/'], ['pattern' => '^a:b$']],
            'regex modifier drops' => [['regex:/x/i'], []],
            'regex unicode kept'   => [['regex:/x/u'], ['pattern' => 'x']],
            'regex and affix'      => [['regex:/^a/', 'starts_with:b'], ['allOf' => [['pattern' => '^a'], ['pattern' => '^(?:b)']]]],
        ];
    }

    /**
     * Test that each rule-token family produces the expected schema fragment.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('mappingProvider')]
    public function testTokensMapToSchemaFragment(array $tokens, array $expected): void
    {
        self::assertSame($expected, (new FieldSchemaBuilder)->build($tokens)['schema']);
    }

    /**
     * Provide date_format rules and the schema each exports.
     *
     * @return iterable<string, array{list<string>, array<string, mixed>}>
     */
    public static function dateFormatProvider(): iterable
    {
        yield 'date' => [['date_format:Y-m-d'], ['type' => 'string', 'format' => 'date', 'examples' => ['2025-01-01']]];
        yield 'atom date-time' => [['date_format:Y-m-d\TH:i:sP'], ['type' => 'string', 'format' => 'date-time', 'examples' => ['2025-01-01T00:00:00+00:00']]];
        yield 'zulu date-time' => [['date_format:Y-m-d\TH:i:sp'], ['type' => 'string', 'format' => 'date-time', 'examples' => ['2025-01-01T00:00:00Z']]];
        yield 'millisecond date-time' => [['date_format:Y-m-d\TH:i:s.vP'], ['type' => 'string', 'format' => 'date-time', 'examples' => ['2025-01-01T00:00:00.000+00:00']]];
        yield 'microsecond date-time' => [['date_format:Y-m-d\TH:i:s.uP'], ['type' => 'string', 'format' => 'date-time', 'examples' => ['2025-01-01T00:00:00.000000+00:00']]];
        yield 'two date-time forms' => [['date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp'], ['type' => 'string', 'format' => 'date-time', 'examples' => ['2025-01-01T00:00:00+00:00']]];
        yield 'own format' => [['date_format:Y-m-d H:i'], ['type' => 'string', 'examples' => ['2025-01-01 00:00']]];
        yield 'mixed forms' => [['date_format:Y-m-d,Y-m-d\TH:i:sP'], ['type' => 'string', 'examples' => ['2025-01-01']]];
        yield 'unreadable format' => [['date_format:c'], ['type' => 'string']];
        yield 'unreadable then own format' => [['date_format:c,d/m/Y'], ['type' => 'string', 'examples' => ['01/01/2025']]];
        yield 'nullable' => [['nullable', 'date_format:Y-m-d'], ['type' => ['string', 'null'], 'format' => 'date', 'examples' => ['2025-01-01']]];
        yield 'listed values' => [['string', 'in:2023,2024', 'date_format:Y'], ['type' => 'string', 'enum' => ['2023', '2024']]];
    }

    /**
     * Test that a date_format field takes the date or date-time format only
     * when every listed format renders that form, with an example in the first
     * format that reads back what it renders unless it lists its values.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('dateFormatProvider')]
    public function testDateFormatExportsTheFormItsFormatsRender(array $tokens, array $expected): void
    {
        self::assertSame($expected, (new FieldSchemaBuilder)->build($tokens)['schema']);
    }

    /**
     * Test that building an enum-class field registers the enum.
     *
     * @return void
     */
    public function testEnumClassTokenRegistersTheEnum(): void
    {
        $enums = new EnumSchemaRegistry;

        (new FieldSchemaBuilder($enums))->build(['enum-class:' . UserStatus::class]);

        self::assertSame([UserStatus::class], $enums->classes());
    }

    /**
     * Test that a required token without a relaxing token marks required.
     *
     * @return void
     */
    public function testRequiredTokenMarksFieldRequired(): void
    {
        self::assertTrue((new FieldSchemaBuilder)->build(['required', 'string'])['required']);
        self::assertTrue((new FieldSchemaBuilder)->build(['accepted'])['required']);
    }

    /**
     * Test that required marks an enum field and an exclude rule relaxes it.
     *
     * @return void
     */
    public function testRequiredTokenMarksEnumFieldRequired(): void
    {
        $builder = new FieldSchemaBuilder;

        self::assertTrue($builder->build(['required', 'enum-class:' . UserStatus::class])['required']);
        self::assertFalse($builder->build(['required', 'exclude', 'enum-class:' . UserStatus::class])['required']);
    }

    /**
     * Test that a sometimes token relaxes an otherwise required field.
     *
     * @return void
     */
    public function testSometimesRelaxesRequired(): void
    {
        self::assertFalse((new FieldSchemaBuilder)->build(['required', 'sometimes', 'string'])['required']);
    }

    /**
     * Test that a field without required or sometimes is not required.
     *
     * @return void
     */
    public function testAbsentPresenceTokenIsNotRequired(): void
    {
        self::assertFalse((new FieldSchemaBuilder)->build(['string'])['required']);
    }

    /**
     * Test that present marks the field required unless sometimes relaxes it.
     *
     * @return void
     */
    public function testPresentTokenMarksFieldRequired(): void
    {
        self::assertTrue((new FieldSchemaBuilder)->build(['present', 'array'])['required']);
        self::assertFalse((new FieldSchemaBuilder)->build(['sometimes', 'present'])['required']);
    }

    /**
     * Test that an exclude rule relaxes an otherwise required field.
     *
     * @return void
     */
    public function testExcludeRuleRelaxesRequired(): void
    {
        self::assertFalse((new FieldSchemaBuilder)->build(['exclude_if:kind,a', 'required', 'string'])['required']);
    }

    /**
     * Provide token lists refusing or allowing empty values, with schemas.
     *
     * @return iterable<string, array{0: list<string>, 1: array<string, mixed>}>
     */
    public static function emptyValueProvider(): iterable
    {
        $reference = ['$ref' => '#/components/schemas/UserStatus'];

        yield from [
            'required array'            => [['required', 'array'], ['type' => 'array', 'minItems' => 1]],
            'filled array'              => [['filled', 'array'], ['type' => 'array', 'minItems' => 1]],
            'sometimes required array'  => [['sometimes', 'required', 'array'], ['type' => 'array', 'minItems' => 1]],
            'required array above one'  => [['required', 'array', 'min:3'], ['type' => 'array', 'minItems' => 3]],
            'required array below one'  => [['required', 'array', 'between:0,2'], ['type' => 'array', 'minItems' => 1, 'maxItems' => 2]],
            'required array sized zero' => [['required', 'array', 'size:0'], ['type' => 'array', 'minItems' => 1, 'maxItems' => 0]],
            'required nullable array'   => [['nullable', 'required', 'array'], ['type' => 'array', 'minItems' => 1]],
            'filled nullable string'    => [['nullable', 'filled', 'string'], ['type' => 'string']],
            'required nullable enum'    => [['required', 'nullable', 'enum-class:' . UserStatus::class], $reference],
            'present array'             => [['present', 'array'], ['type' => 'array']],
            'excludable required array' => [['exclude_if:kind,a', 'required', 'nullable', 'array'], ['type' => ['array', 'null']]],
            'excludable filled array'   => [['filled', 'exclude_unless:kind,a', 'array'], ['type' => 'array']],
            'required string'           => [['required', 'string', 'min:0'], ['type' => 'string', 'minLength' => 0]],
        ];
    }

    /**
     * Test that refusing empty values forbids empty arrays and null.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('emptyValueProvider')]
    public function testAFieldRefusingAnEmptyValueRefusesAnEmptyArrayAndNull(array $tokens, array $expected): void
    {
        self::assertSame($expected, (new FieldSchemaBuilder)->build($tokens)['schema']);
    }

    /**
     * Test that a non-enum class token contributes no reference.
     *
     * @return void
     */
    public function testATokenNamingSomethingThatIsNotAnEnumContributesNoReference(): void
    {
        $schema = (new FieldSchemaBuilder)->build(['enum-class:Tests\Fixtures\Models\User'])['schema'];

        self::assertSame([], $schema);
    }

    /**
     * Provide the token lists of fields that may never be sent.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function neverSentProvider(): iterable
    {
        yield from [
            'prohibited'          => [['prohibited']],
            'required prohibited' => [['required', 'string', 'prohibited']],
            'missing'             => [['missing']],
            'required missing'    => [['required', 'string', 'missing']],
        ];
    }

    /**
     * Test that a never-sendable field is the false schema, not required.
     *
     * @param  list<string>  $tokens
     * @return void
     */
    #[DataProvider('neverSentProvider')]
    public function testAFieldThatMayNeverBeSentIsTheFalseSchemaAndNotRequired(array $tokens): void
    {
        self::assertSame(['schema' => false, 'required' => false], (new FieldSchemaBuilder)->build($tokens));
    }

    /**
     * Test that a conditional prohibition leaves the other rules in charge.
     *
     * @return void
     */
    public function testAConditionalProhibitionKeepsTheSchemaOfTheOtherRules(): void
    {
        $builder = new FieldSchemaBuilder;

        self::assertSame(
            ['schema' => ['type' => 'string', 'maxLength' => 9], 'required' => true],
            $builder->build(['required', 'string', 'max:9', 'prohibited_if:kind,a']),
        );
        self::assertSame(
            ['schema' => ['type' => 'integer'], 'required' => false],
            $builder->build(['integer', 'prohibited_unless:kind,b', 'missing_if:kind,c']),
        );
    }

    /**
     * Test that a field with only untranslatable rules builds an empty schema.
     *
     * @return void
     */
    public function testAFieldWithOnlyUntranslatableRulesBuildsAnEmptySchema(): void
    {
        self::assertSame(['schema' => [], 'required' => false], (new FieldSchemaBuilder)->build(['sometimes', 'nullable', 'timezone']));
    }
}
