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
}
