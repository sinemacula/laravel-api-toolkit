<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

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
        yield 'varchar string' => ['varchar', 'string', null];
        yield 'text string' => ['text', 'string', null];
        yield 'tinytext string' => ['tinytext', 'string', null];
        yield 'mediumtext string' => ['mediumtext', 'string', null];
        yield 'longtext string' => ['longtext', 'string', null];
        yield 'string string' => ['string', 'string', null];
        yield 'enum string' => ['enum', 'string', null];
        yield 'set string' => ['set', 'string', null];
        yield 'uuid format' => ['uuid', 'string', 'uuid'];
        yield 'bigint integer' => ['bigint', 'integer', null];
        yield 'int integer' => ['int', 'integer', null];
        yield 'integer integer' => ['integer', 'integer', null];
        yield 'mediumint integer' => ['mediumint', 'integer', null];
        yield 'smallint integer' => ['smallint', 'integer', null];
        yield 'tinyint integer' => ['tinyint', 'integer', null];
        yield 'serial integer' => ['serial', 'integer', null];
        yield 'bigserial integer' => ['bigserial', 'integer', null];
        yield 'decimal number' => ['decimal', 'number', null];
        yield 'numeric number' => ['numeric', 'number', null];
        yield 'float number' => ['float', 'number', null];
        yield 'double number' => ['double', 'number', null];
        yield 'real number' => ['real', 'number', null];
        yield 'money number' => ['money', 'number', null];
        yield 'boolean boolean' => ['boolean', 'boolean', null];
        yield 'bool boolean' => ['bool', 'boolean', null];
        yield 'date format' => ['date', 'string', 'date'];
        yield 'datetime format' => ['datetime', 'string', 'date-time'];
        yield 'timestamp format' => ['timestamp', 'string', 'date-time'];
        yield 'datetimetz format' => ['datetimetz', 'string', 'date-time'];
        yield 'timestamptz format' => ['timestamptz', 'string', 'date-time'];
        yield 'time format' => ['time', 'string', 'time'];
        yield 'timetz format' => ['timetz', 'string', 'time'];
        yield 'binary byte' => ['binary', 'string', 'byte'];
        yield 'blob byte' => ['blob', 'string', 'byte'];
        yield 'bytea byte' => ['bytea', 'string', 'byte'];
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
     * Test that a datetime cast resolves to a date-time format.
     *
     * @return void
     */
    public function testDatetimeCastResolvesToDateTimeFormat(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'verified_at', typeName: 'varchar', nullable: true);

        $schema = $mapper->map($column, 'datetime');

        self::assertSame('string', $schema->type);
        self::assertSame('date-time', $schema->format);
        self::assertTrue($schema->nullable);
    }

    /**
     * Test that an immutable datetime cast resolves to a date-time format.
     *
     * @return void
     */
    public function testImmutableDatetimeCastResolvesToDateTimeFormat(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'created_at', typeName: 'varchar', nullable: false);

        $schema = $mapper->map($column, 'immutable_datetime');

        self::assertSame('date-time', $schema->format);
    }

    /**
     * Test that a date cast resolves to a date format.
     *
     * @return void
     */
    public function testDateCastResolvesToDateFormat(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'dob', typeName: 'varchar', nullable: false);

        $schema = $mapper->map($column, 'date');

        self::assertSame('string', $schema->type);
        self::assertSame('date', $schema->format);
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
     * Test that an encrypted array cast resolves off the full cast token.
     *
     * @return void
     */
    public function testEncryptedArrayCastResolvesToArray(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'meta', typeName: 'varchar', nullable: false);

        $schema = $mapper->map($column, 'encrypted:array');

        self::assertSame('array', $schema->type);
    }

    /**
     * Test that a datetime cast with a format still resolves to date-time.
     *
     * @return void
     */
    public function testDatetimeCastWithFormatArgumentResolvesToDateTime(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'published_at', typeName: 'varchar', nullable: false);

        $schema = $mapper->map($column, 'datetime:Y-m-d H:i:s');

        self::assertSame('date-time', $schema->format);
    }

    /**
     * Test that an array cast beats a json column.
     *
     * @return void
     */
    public function testArrayCastResolvesToArray(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'meta', typeName: 'json', nullable: false);

        $schema = $mapper->map($column, 'array');

        self::assertSame('array', $schema->type);
    }

    /**
     * Test that a collection cast resolves to an array schema.
     *
     * @return void
     */
    public function testCollectionCastResolvesToArray(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'tags', typeName: 'text', nullable: false);

        $schema = $mapper->map($column, 'collection');

        self::assertSame('array', $schema->type);
    }

    /**
     * Test that an object cast resolves to an object schema.
     *
     * @return void
     */
    public function testObjectCastResolvesToObject(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'payload', typeName: 'json', nullable: false);

        $schema = $mapper->map($column, 'object');

        self::assertSame('object', $schema->type);
    }

    /**
     * Test that an unrecognised cast falls back to the column type mapping.
     *
     * @return void
     */
    public function testUnrecognisedCastFallsBackToColumnType(): void
    {
        $mapper = new ColumnTypeMapper;
        $column = new ColumnDefinition(name: 'status', typeName: 'varchar', nullable: false);

        $schema = $mapper->map($column, 'App\Casts\StatusCast');

        self::assertSame('string', $schema->type);
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
     * Test that a mappable cast resolves without a column, non-nullable.
     *
     * @return void
     */
    public function testMapsFromCastWhenColumnAbsent(): void
    {
        $mapper = new ColumnTypeMapper;

        $schema = $mapper->map(null, 'boolean');

        self::assertSame('boolean', $schema->type);
        self::assertFalse($schema->nullable);
        self::assertFalse($schema->undocumented);
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
}
