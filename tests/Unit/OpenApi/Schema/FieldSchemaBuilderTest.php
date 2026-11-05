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
            'ip format'            => [['ip'], ['type' => 'string', 'format' => 'ipv4']],
            'date format'          => [['date'], ['type' => 'string', 'format' => 'date']],
            'date_format format'   => [['date_format:Y-m-d H:i'], ['type' => 'string', 'format' => 'date-time']],
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
            'nullable date'        => [['nullable', 'date'], ['type' => ['string', 'null'], 'format' => 'date']],
            'file binary'          => [['file'], ['type' => 'string', 'format' => 'binary']],
            'image binary'         => [['image'], ['type' => 'string', 'format' => 'binary']],
            'unknown rule skipped' => [['string', 'starts_with:foo'], ['type' => 'string']],
            'boolean size skipped' => [['boolean', 'min:1'], ['type' => 'boolean']],
            'array keys arguments' => [['array:name,email'], ['type' => 'array']],
            'regex two char pairs' => [['regex://'], ['pattern' => '']],
            'regex dotall newline' => [["regex:/a\nb/"], ['pattern' => "a\nb"]],
            'regex caret anchored' => [['regex:a/b/'], ['pattern' => 'a/b/']],
            'regex dollar trailer' => [['regex:/abc/;'], ['pattern' => '/abc/;']],
            'regex colon argument' => [['regex:/^a:b$/'], ['pattern' => '^a:b$']],
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
     * Test that building an enum-class field registers the enum so its
     * component is later emitted.
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
     * Test that a required token without a relaxing token marks the field
     * required.
     *
     * @return void
     */
    public function testRequiredTokenMarksFieldRequired(): void
    {
        self::assertTrue((new FieldSchemaBuilder)->build(['required', 'string'])['required']);
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
     * Test that a field with neither a required nor sometimes token is not
     * required.
     *
     * @return void
     */
    public function testAbsentPresenceTokenIsNotRequired(): void
    {
        self::assertFalse((new FieldSchemaBuilder)->build(['string'])['required']);
    }

    /**
     * Test that a token naming a class that is not an enum contributes no
     * reference, so a stale rule documents nothing rather than a broken $ref.
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
     * Test that a field that may never be sent is the false schema and is not
     * required, even when another of its rules says required.
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
     * Test that a conditional prohibition, which cannot be expressed
     * statically, leaves the field documented by its other rules.
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
     * Test that a field validated only by rules with no schema translation
     * builds an empty schema.
     *
     * @return void
     */
    public function testAFieldWithOnlyUntranslatableRulesBuildsAnEmptySchema(): void
    {
        self::assertSame(['schema' => [], 'required' => false], (new FieldSchemaBuilder)->build(['sometimes', 'nullable', 'accepted']));
    }
}
