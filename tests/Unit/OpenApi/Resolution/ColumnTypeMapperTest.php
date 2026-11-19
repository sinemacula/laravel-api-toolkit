<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\AsUri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use Tests\Fixtures\Casts\PostalAddress;
use Tests\Fixtures\Casts\SluggedTitleCast;
use Tests\Fixtures\Enums\Platform;

/**
 * Tests for the ColumnTypeMapper.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnTypeMapper::class)]
final class ColumnTypeMapperTest extends TestCase
{
    /**
     * Provide each documented type-name row with its expected mapping.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function typeNameProvider(): iterable
    {
        yield 'char string' => ['char', 'string', null];
        yield 'bpchar string' => ['bpchar', 'string', null];
        yield 'varchar string' => ['varchar', 'string', null];
        yield 'text string' => ['text', 'string', null];
        yield 'tinytext string' => ['tinytext', 'string', null];
        yield 'mediumtext string' => ['mediumtext', 'string', null];
        yield 'longtext string' => ['longtext', 'string', null];
        yield 'enum string' => ['enum', 'string', null];
        yield 'set string' => ['set', 'string', null];
        yield 'money string' => ['money', 'string', null];
        yield 'uuid format' => ['uuid', 'string', 'uuid'];
        yield 'bigint integer' => ['bigint', 'integer', null];
        yield 'int integer' => ['int', 'integer', null];
        yield 'integer integer' => ['integer', 'integer', null];
        yield 'mediumint integer' => ['mediumint', 'integer', null];
        yield 'smallint integer' => ['smallint', 'integer', null];
        yield 'tinyint integer' => ['tinyint', 'integer', null];
        yield 'int2 integer' => ['int2', 'integer', null];
        yield 'int4 integer' => ['int4', 'integer', null];
        yield 'int8 integer' => ['int8', 'integer', null];
        yield 'float number' => ['float', 'number', null];
        yield 'double number' => ['double', 'number', null];
        yield 'real number' => ['real', 'number', null];
        yield 'boolean boolean' => ['boolean', 'boolean', null];
        yield 'bool boolean' => ['bool', 'boolean', null];
        yield 'date format' => ['date', 'string', 'date'];
        yield 'datetime string' => ['datetime', 'string', null];
        yield 'timestamp string' => ['timestamp', 'string', null];
        yield 'timestamptz string' => ['timestamptz', 'string', null];
        yield 'time string' => ['time', 'string', null];
        yield 'timetz string' => ['timetz', 'string', null];
    }

    /**
     * Test that each driver type maps to its JSON Schema type and format.
     *
     * @param  string  $typeName
     * @param  string  $expectedType
     * @param  string|null  $expectedFormat
     * @return void
     */
    #[DataProvider('typeNameProvider')]
    public function testMapsTypeNameToSchema(string $typeName, string $expectedType, ?string $expectedFormat): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'column', typeName: $typeName, nullable: false);

        $schema = $mapper->map($column);

        self::assertSame($expectedType, $schema->type);
        self::assertSame($expectedFormat, $schema->format);
        self::assertFalse($schema->nullable);
        self::assertFalse($schema->undocumented);
    }

    /**
     * Test that the driver type name is matched case-insensitively.
     *
     * @return void
     */
    public function testMatchesTypeNameCaseInsensitively(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'id', typeName: 'BIGINT', nullable: false);

        $schema = $mapper->map($column);

        self::assertSame('integer', $schema->type);
    }

    /**
     * Test that a nullable column produces a nullable schema.
     *
     * @return void
     */
    public function testCarriesColumnNullability(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'bio', typeName: 'varchar', nullable: true);

        $schema = $mapper->map($column);

        self::assertSame('string', $schema->type);
        self::assertTrue($schema->nullable);
    }

    /**
     * Provide each type name that resolves undocumented without a cast.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function undocumentedTypeNameProvider(): iterable
    {
        yield 'unknown point' => ['point'];
        yield 'json' => ['json'];
        yield 'jsonb' => ['jsonb'];
        yield 'decimal' => ['decimal'];
        yield 'numeric' => ['numeric'];
        yield 'binary' => ['binary'];
        yield 'blob' => ['blob'];
        yield 'bytea' => ['bytea'];
    }

    /**
     * Test that an indescribable type without a cast resolves undocumented.
     *
     * @param  string  $typeName
     * @return void
     */
    #[DataProvider('undocumentedTypeNameProvider')]
    public function testUndocumentedTypeNameResolvesToUndocumented(string $typeName): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'column', typeName: $typeName, nullable: false);

        $schema = $mapper->map($column);

        self::assertNull($schema->type);
        self::assertTrue($schema->undocumented);
    }

    /**
     * Test that a boolean cast overrides a tinyint column.
     *
     * @return void
     */
    public function testBooleanCastOverridesTinyintColumn(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'active', typeName: 'tinyint', nullable: false);

        $schema = $mapper->map($column, 'boolean');

        self::assertSame('boolean', $schema->type);
    }

    /**
     * Test that absent a cast, a tinyint column maps conservatively to integer.
     *
     * @return void
     */
    public function testTinyintWithoutCastMapsToInteger(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'active', typeName: 'tinyint', nullable: false);

        $schema = $mapper->map($column);

        self::assertSame('integer', $schema->type);
    }

    /**
     * Provide each PostgreSQL float type under each fetch mode, with the type
     * its value fetches as.
     *
     * @return iterable<string, array{0: string, 1: bool, 2: string}>
     */
    public static function postgresFloatProvider(): iterable
    {
        yield 'float4 fetched natively' => ['float4', true, 'number'];
        yield 'float8 fetched natively' => ['float8', true, 'number'];
        yield 'float4 fetched as a string' => ['float4', false, 'string'];
        yield 'float8 fetched as a string' => ['float8', false, 'string'];
    }

    /**
     * Test that a PostgreSQL float column takes the type its value fetches as.
     *
     * @param  string  $typeName
     * @param  bool  $fetchesNativeFloats
     * @param  string  $type
     * @return void
     */
    #[DataProvider('postgresFloatProvider')]
    public function testPostgresFloatTakesTheTypeItFetchesAs(string $typeName, bool $fetchesNativeFloats, string $type): void
    {
        $column = new ColumnDefinition(name: 'ratio', typeName: $typeName, nullable: false);

        self::assertSame(['type' => $type], (new ColumnTypeMapper($fetchesNativeFloats))->map($column)->toArray());
    }

    /**
     * Test that PostgreSQL floats fetch natively from PHP 8.4 by default.
     *
     * @return void
     */
    public function testPostgresFloatsFetchNativelyFromPhp84ByDefault(): void
    {
        $column = new ColumnDefinition(name: 'ratio', typeName: 'float8', nullable: false);

        self::assertSame(PHP_VERSION_ID >= 80400 ? 'number' : 'string', (new ColumnTypeMapper)->map($column)->type);
    }

    /**
     * Test that a cast is matched case-insensitively, as Eloquent does.
     *
     * @return void
     */
    public function testMatchesCastCaseInsensitively(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'active', typeName: 'varchar', nullable: false);

        $schema = $mapper->map($column, 'BOOLEAN');

        self::assertSame('boolean', $schema->type);
    }

    /**
     * Test that the mapper returns an OpenApiFieldSchema instance.
     *
     * @return void
     */
    public function testReturnsOpenApiFieldSchemaInstance(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'id', typeName: 'bigint', nullable: false);

        self::assertInstanceOf(OpenApiFieldSchema::class, $mapper->map($column));
    }

    /**
     * Test that an absent column and cast resolves undocumented.
     *
     * @return void
     */
    public function testUndocumentedWhenColumnAndCastAbsent(): void
    {
        $mapper = new ColumnTypeMapper;

        $schema = $mapper->map(null, null);

        self::assertTrue($schema->undocumented);
        self::assertNull($schema->type);
    }

    /**
     * Provide each documented cast and the schema of the value it returns.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function documentedCastProvider(): iterable
    {
        yield 'bool' => ['bool', ['type' => 'boolean']];
        yield 'boolean' => ['boolean', ['type' => 'boolean']];
        yield 'int' => ['int', ['type' => 'integer']];
        yield 'integer' => ['integer', ['type' => 'integer']];
        yield 'uppercase integer' => ['INTEGER', ['type' => 'integer']];
        yield 'padded integer' => [' integer ', ['type' => 'integer']];
        yield 'timestamp' => ['timestamp', ['type' => 'integer']];
        yield 'float' => ['float', ['type' => 'number']];
        yield 'double' => ['double', ['type' => 'number']];
        yield 'real' => ['real', ['type' => 'number']];
        yield 'string' => ['string', ['type' => 'string']];
        yield 'encrypted' => ['encrypted', ['type' => 'string']];
        yield 'hashed' => ['hashed', ['type' => 'string']];
        yield 'decimal' => ['decimal:2', ['type' => 'string']];
        yield 'stringable' => [AsStringable::class, ['type' => 'string']];
        yield 'uri' => [AsUri::class, ['type' => 'string']];
        yield 'date' => ['date', ['type' => 'string', 'format' => 'date-time']];
        yield 'datetime' => ['datetime', ['type' => 'string', 'format' => 'date-time']];
        yield 'immutable date' => ['immutable_date', ['type' => 'string', 'format' => 'date-time']];
        yield 'immutable datetime' => ['immutable_datetime', ['type' => 'string', 'format' => 'date-time']];
        yield 'uppercase datetime' => ['DATETIME', ['type' => 'string', 'format' => 'date-time']];
        yield 'formatted date' => ['date:Y-m-d', ['type' => 'string', 'format' => 'date-time']];
        yield 'formatted datetime' => ['datetime:Y-m-d H:i:s', ['type' => 'string', 'format' => 'date-time']];
        yield 'formatted immutable date' => ['immutable_date:Y-m-d', ['type' => 'string', 'format' => 'date-time']];
        yield 'formatted immutable datetime' => ['immutable_datetime:Y-m-d H:i', ['type' => 'string', 'format' => 'date-time']];
    }

    /**
     * Test that a documented cast decides the schema, with or without a column
     * of another type.
     *
     * @param  string  $cast
     * @param  array<string, string>  $expected
     * @return void
     */
    #[DataProvider('documentedCastProvider')]
    public function testDocumentedCastDecidesTheSchema(string $cast, array $expected): void
    {
        $column = new ColumnDefinition(name: 'value', typeName: 'json', nullable: false);

        self::assertSame($expected, (new ColumnTypeMapper)->map(null, $cast)->toArray());
        self::assertSame($expected, (new ColumnTypeMapper)->map($column, $cast)->toArray());
    }

    /**
     * Provide each cast whose value has no fixed shape, or is decided by a
     * class the mapper does not know.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function undocumentedCastProvider(): iterable
    {
        yield 'array' => ['array'];
        yield 'json' => ['json'];
        yield 'unicode json' => ['json:unicode'];
        yield 'collection' => ['collection'];
        yield 'object' => ['object'];
        yield 'encrypted array' => ['encrypted:array'];
        yield 'encrypted collection' => ['encrypted:collection'];
        yield 'encrypted json' => ['encrypted:json'];
        yield 'encrypted object' => ['encrypted:object'];
        yield 'array object class' => [AsArrayObject::class];
        yield 'collection class' => [AsCollection::class];
        yield 'encrypted array object class' => [AsEncryptedArrayObject::class];
        yield 'encrypted collection class' => [AsEncryptedCollection::class];
        yield 'enum collection class' => [AsEnumCollection::of(Platform::class)];
        yield 'fluent class' => [AsFluent::class];
        yield 'custom cast class' => [SluggedTitleCast::class];
        yield 'castable class' => [PostalAddress::class];
        yield 'parameterised integer lookalike' => ['integer:strict'];
        yield 'parameterised string lookalike' => ['string:trimmed'];
        yield 'capitalised formatted date' => ['Date:Y-m-d'];
        yield 'capitalised decimal' => ['Decimal:2'];
    }

    /**
     * Test that a cast the mapper cannot resolve is undocumented, never read
     * from the column.
     *
     * @param  string  $cast
     * @return void
     */
    #[DataProvider('undocumentedCastProvider')]
    public function testUnresolvedCastIsUndocumentedWhateverTheColumn(string $cast): void
    {
        $column = new ColumnDefinition(name: 'value', typeName: 'integer', nullable: false);

        self::assertTrue((new ColumnTypeMapper)->map(null, $cast)->undocumented);
        self::assertTrue((new ColumnTypeMapper)->map($column, $cast)->undocumented);
    }

    /**
     * Test that a cast documents a column type whose own value is undocumented.
     *
     * @return void
     */
    public function testCastDocumentsAnUndocumentedColumnType(): void
    {
        $column = new ColumnDefinition(name: 'price', typeName: 'decimal', nullable: true);

        self::assertSame(['type' => ['string', 'null']], (new ColumnTypeMapper)->map($column, 'decimal:2')->toArray());
        self::assertSame(['type' => ['number', 'null']], (new ColumnTypeMapper)->map($column, 'float')->toArray());
    }

    /**
     * Test that a scalar cast wins over a contrary column type, keeping the
     * column's nullability.
     *
     * @return void
     */
    public function testScalarCastWinsOverAContraryColumn(): void
    {
        $column = new ColumnDefinition(name: 'code', typeName: 'varchar', nullable: true);

        self::assertSame(['type' => ['integer', 'null']], (new ColumnTypeMapper)->map($column, 'int')->toArray());
    }

    /**
     * Test that a column keeps its format under a formatless cast of its type.
     *
     * @return void
     */
    public function testColumnFormatRefinesAFormatlessCastOfItsType(): void
    {
        $column = new ColumnDefinition(name: 'id', typeName: 'uuid', nullable: true);

        self::assertSame(['type' => ['string', 'null'], 'format' => 'uuid'], (new ColumnTypeMapper)->map($column, 'string')->toArray());
    }

    /**
     * Test that a cast's own format wins over a column of the same type.
     *
     * @return void
     */
    public function testCastFormatWinsOverAColumnOfTheSameType(): void
    {
        $column = new ColumnDefinition(name: 'born_on', typeName: 'date', nullable: false);

        self::assertSame(['type' => 'string', 'format' => 'date-time'], (new ColumnTypeMapper)->map($column, 'date')->toArray());
    }
}
