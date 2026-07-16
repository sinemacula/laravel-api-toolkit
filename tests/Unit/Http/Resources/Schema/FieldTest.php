<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Tests for the Field schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Field::class)]
final class FieldTest extends TestCase
{
    /**
     * Test that scalar creates a field with the given name.
     *
     * @return void
     */
    public function testScalarCreatesFieldWithGivenName(): void
    {
        $field = Field::scalar('email');

        $array = $field->toArray();

        self::assertArrayHasKey('email', $array);
        self::assertSame([], $array['email']);
    }

    /**
     * Test that scalar with alias exposes the field under the alias key.
     *
     * @return void
     */
    public function testScalarWithAliasExposesUnderAliasKey(): void
    {
        $field = Field::scalar('email_address', 'email');

        $array = $field->toArray();

        self::assertArrayHasKey('email', $array);
        self::assertArrayNotHasKey('email_address', $array);
    }

    /**
     * Test that filterable() and sortable() emit the field's column name.
     *
     * @return void
     */
    public function testFilterableAndSortableMarkersEmitTheColumnName(): void
    {
        $array = Field::scalar('email')->filterable()->sortable()->toArray();

        self::assertSame('email', $array['email']['filterable']);
        self::assertSame('email', $array['email']['sortable']);
    }

    /**
     * Test that the filterable marker declares the underlying column, not the
     * presentation alias.
     *
     * @return void
     */
    public function testFilterableMarkerDeclaresColumnNotAlias(): void
    {
        $array = Field::scalar('email_address', 'email')->filterable()->toArray();

        self::assertSame('email_address', $array['email']['filterable']);
    }

    /**
     * Test that a field without markers omits them.
     *
     * @return void
     */
    public function testFieldWithoutMarkersOmitsThem(): void
    {
        $array = Field::scalar('email')->toArray();

        self::assertArrayNotHasKey('filterable', $array['email']);
        self::assertArrayNotHasKey('sortable', $array['email']);
    }

    /**
     * Test that accessor creates a field with a string accessor.
     *
     * @return void
     */
    public function testAccessorCreatesFieldWithStringAccessor(): void
    {
        $field = Field::accessor('display_name', 'name');

        $array = $field->toArray();

        self::assertArrayHasKey('display_name', $array);
        self::assertSame('name', $array['display_name']['accessor']);
    }

    /**
     * Test that accessor creates a field with a callable accessor.
     *
     * @return void
     */
    public function testAccessorCreatesFieldWithCallableAccessor(): void
    {
        $accessor = fn ($resource) => $resource->name;
        $field    = Field::accessor('display_name', $accessor);

        $array = $field->toArray();

        self::assertSame($accessor, $array['display_name']['accessor']);
    }

    /**
     * Test that timestamp creates a field with an accessor callable.
     *
     * @return void
     */
    public function testTimestampCreatesFieldWithAccessor(): void
    {
        $field = Field::timestamp('created_at');

        $array = $field->toArray();

        self::assertArrayHasKey('created_at', $array);
        self::assertArrayHasKey('accessor', $array['created_at']);
        self::assertIsCallable($array['created_at']['accessor']);
    }

    /**
     * Test that the timestamp accessor formats a Carbon value as an ISO-8601
     * string.
     *
     * @return void
     */
    public function testTimestampAccessorFormatsCarbonValueAsIso8601(): void
    {
        $accessor = Field::timestamp('created_at')->toArray()['created_at']['accessor'];

        self::assertIsCallable($accessor);

        $carbon = Carbon::parse('2026-07-15T09:30:00+00:00');

        self::assertSame($carbon->toIso8601String(), $accessor(['created_at' => $carbon]));
    }

    /**
     * Test that the timestamp accessor returns null for a non-Carbon value.
     *
     * @return void
     */
    public function testTimestampAccessorReturnsNullForNonCarbonValue(): void
    {
        $accessor = Field::timestamp('created_at')->toArray()['created_at']['accessor'];

        self::assertIsCallable($accessor);

        self::assertNull($accessor(['created_at' => null]));
    }

    /**
     * Test that date creates a field with an accessor callable.
     *
     * @return void
     */
    public function testDateCreatesFieldWithAccessor(): void
    {
        $field = Field::date('birth_date');

        $array = $field->toArray();

        self::assertArrayHasKey('birth_date', $array);
        self::assertArrayHasKey('accessor', $array['birth_date']);
        self::assertIsCallable($array['birth_date']['accessor']);
    }

    /**
     * Test that the date accessor formats a Carbon value as a date string.
     *
     * @return void
     */
    public function testDateAccessorFormatsCarbonValueAsDateString(): void
    {
        $accessor = Field::date('birth_date')->toArray()['birth_date']['accessor'];

        self::assertIsCallable($accessor);

        $carbon = Carbon::parse('2026-07-15T09:30:00+00:00');

        self::assertSame('2026-07-15', $accessor(['birth_date' => $carbon]));
    }

    /**
     * Test that the date accessor returns null for a non-Carbon value.
     *
     * @return void
     */
    public function testDateAccessorReturnsNullForNonCarbonValue(): void
    {
        $accessor = Field::date('birth_date')->toArray()['birth_date']['accessor'];

        self::assertIsCallable($accessor);

        self::assertNull($accessor(['birth_date' => 'not-a-carbon']));
    }

    /**
     * Test that compute creates a field with a compute callable.
     *
     * @return void
     */
    public function testComputeCreatesFieldWithComputeCallable(): void
    {
        $compute = fn ($resource) => $resource->first_name . ' ' . $resource->last_name;
        $field   = Field::compute('full_name', $compute);

        $array = $field->toArray();

        self::assertArrayHasKey('full_name', $array);
        self::assertSame($compute, $array['full_name']['compute']);
    }

    /**
     * Test that compute with alias uses the alias key.
     *
     * @return void
     */
    public function testComputeWithAliasUsesAliasKey(): void
    {
        $compute = fn () => 'value';
        $field   = Field::compute('full_name', $compute, 'display_name');

        $array = $field->toArray();

        self::assertArrayHasKey('display_name', $array);
        self::assertArrayNotHasKey('full_name', $array);
    }

    /**
     * Test that alias changes the output key.
     *
     * @return void
     */
    public function testAliasChangesOutputKey(): void
    {
        $field = Field::scalar('email_address');

        $field->alias('email');

        $array = $field->toArray();

        self::assertArrayHasKey('email', $array);
        self::assertArrayNotHasKey('email_address', $array);
    }

    /**
     * Test that toArray returns correct normalized structure for scalar field.
     *
     * @return void
     */
    public function testToArrayReturnsCorrectStructureForScalar(): void
    {
        $field = Field::scalar('name');

        $array = $field->toArray();

        self::assertSame(['name' => []], $array);
    }

    /**
     * Test that toArray filters out null and empty values.
     *
     * @return void
     */
    public function testToArrayFiltersOutNullAndEmptyValues(): void
    {
        $field = Field::scalar('name');

        $array = $field->toArray();

        self::assertArrayNotHasKey('accessor', $array['name']);
        self::assertArrayNotHasKey('compute', $array['name']);
        self::assertArrayNotHasKey('extras', $array['name']);
        self::assertArrayNotHasKey('guards', $array['name']);
        self::assertArrayNotHasKey('transformers', $array['name']);
    }

    /**
     * Test that toArray includes extras when set.
     *
     * @return void
     */
    public function testToArrayIncludesExtrasWhenSet(): void
    {
        $field = Field::scalar('avatar');

        $field->extras('media', 'media.thumbnails');

        $array = $field->toArray();

        self::assertSame(['media', 'media.thumbnails'], $array['avatar']['extras']);
    }

    /**
     * Test that toArray includes guards when set.
     *
     * @return void
     */
    public function testToArrayIncludesGuardsWhenSet(): void
    {
        $guard = fn () => true;
        $field = Field::scalar('name')->guard($guard);

        $array = $field->toArray();

        self::assertArrayHasKey('guards', $array['name']);
        self::assertSame([$guard], $array['name']['guards']);
    }

    /**
     * Test that toArray includes transformers when set.
     *
     * @return void
     */
    public function testToArrayIncludesTransformersWhenSet(): void
    {
        $transformer = fn ($resource, $value) => strtoupper($value);
        $field       = Field::scalar('name')->transform($transformer);

        $array = $field->toArray();

        self::assertArrayHasKey('transformers', $array['name']);
        self::assertSame([$transformer], $array['name']['transformers']);
    }

    /**
     * Test that set merges multiple definitions.
     *
     * @return void
     */
    public function testSetMergesMultipleDefinitions(): void
    {
        $result = Field::set(
            Field::scalar('name'),
            Field::scalar('email'),
            Field::scalar('status'),
        );

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('status', $result);
    }

    /**
     * Test that set throws on duplicate key from separate definitions.
     *
     * @return void
     */
    public function testSetThrowsOnDuplicateKeyFromSeparateDefinitions(): void
    {
        $this->expectException(DuplicateSchemaKeyException::class);

        Field::set(
            Field::scalar('name'),
            Field::accessor('name', fn ($resource) => 'computed'),
        );
    }

    /**
     * Test that set throws on duplicate key from Arrayable and scalar.
     *
     * @return void
     */
    public function testSetThrowsOnDuplicateKeyFromArrayableAndScalar(): void
    {
        $this->expectException(DuplicateSchemaKeyException::class);

        Field::set(
            Field::scalar('email'),
            ['email' => []],
        );
    }

    /**
     * Test that set accepts unique keys without exception.
     *
     * @return void
     */
    public function testSetAcceptsUniqueKeysWithoutException(): void
    {
        $result = Field::set(
            Field::scalar('name'),
            Field::scalar('email'),
            Field::scalar('status'),
        );

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('status', $result);
    }

    /**
     * Test that set exception message contains the duplicate key name.
     *
     * @return void
     */
    public function testSetExceptionMessageContainsDuplicateKeyName(): void
    {
        $this->expectException(DuplicateSchemaKeyException::class);
        $this->expectExceptionMessage('Duplicate schema key "title" detected in Field::set()');

        Field::set(
            Field::scalar('title'),
            Field::scalar('title'),
        );
    }

    /**
     * Test that set accepts Arrayable instances.
     *
     * @return void
     */
    public function testSetAcceptsArrayableInstances(): void
    {
        $field1 = Field::scalar('name');
        $field2 = Field::scalar('email');

        $result = Field::set($field1, $field2);

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
    }

    /**
     * Provide factory method variations.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Schema\Field, string}>
     */
    public static function factoryMethodProvider(): iterable
    {
        yield 'scalar' => [Field::scalar('id'), 'id'];
        yield 'scalar with alias' => [Field::scalar('user_id', 'uid'), 'uid'];
        yield 'accessor with string' => [Field::accessor('label', 'name'), 'label'];
        yield 'timestamp' => [Field::timestamp('created_at'), 'created_at'];
        yield 'timestamp with alias' => [Field::timestamp('created_at', 'created'), 'created'];
        yield 'date' => [Field::date('birth_date'), 'birth_date'];
        yield 'date with alias' => [Field::date('birth_date', 'dob'), 'dob'];
        yield 'compute' => [Field::compute('full_name', fn () => 'value'), 'full_name'];
        yield 'compute with alias' => [Field::compute('full_name', fn () => 'value', 'name'), 'name'];
    }

    /**
     * Test static factory methods create Field instances.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Field  $field
     * @param  string  $expectedKey
     * @return void
     */
    #[DataProvider('factoryMethodProvider')]
    public function testFactoryMethodsCreateFieldInstances(Field $field, string $expectedKey): void
    {
        $array = $field->toArray();

        self::assertArrayHasKey($expectedKey, $array);
    }

    /**
     * Test that the openapi key is absent when no declaration was made.
     *
     * This is the byte-for-byte backward-compatibility oracle (AC-11): a scalar
     * field with no openapi() call must serialize identically.
     *
     * @return void
     */
    public function testToArrayOmitsOpenApiWhenNotDeclared(): void
    {
        $field = Field::scalar('name');

        $array = $field->toArray();

        self::assertArrayNotHasKey('openapi', $array['name']);
        self::assertSame(['name' => []], $array);
    }

    /**
     * Test that the openapi key is emitted when a declaration was made.
     *
     * @return void
     */
    public function testToArrayIncludesOpenApiWhenDeclared(): void
    {
        $field = Field::scalar('status');
        $field->openapi()->type('string')->enum(['draft', 'published']);

        $array = $field->toArray();

        self::assertArrayHasKey('openapi', $array['status']);
        self::assertInstanceOf(OpenApiFieldSchema::class, $array['status']['openapi']);
        self::assertSame('string', $array['status']['openapi']->type);
        self::assertSame(['draft', 'published'], $array['status']['openapi']->enum);
    }
}
