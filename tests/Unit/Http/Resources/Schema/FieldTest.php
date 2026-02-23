<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

/**
 * Tests for the Field schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Field::class)]
class FieldTest extends TestCase
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

        static::assertArrayHasKey('email', $array);
        static::assertSame([], $array['email']);
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

        static::assertArrayHasKey('email', $array);
        static::assertArrayNotHasKey('email_address', $array);
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

        static::assertArrayHasKey('display_name', $array);
        static::assertSame('name', $array['display_name']['accessor']);
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

        static::assertSame($accessor, $array['display_name']['accessor']);
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

        static::assertArrayHasKey('created_at', $array);
        static::assertArrayHasKey('accessor', $array['created_at']);
        static::assertIsCallable($array['created_at']['accessor']);
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

        static::assertArrayHasKey('birth_date', $array);
        static::assertArrayHasKey('accessor', $array['birth_date']);
        static::assertIsCallable($array['birth_date']['accessor']);
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

        static::assertArrayHasKey('full_name', $array);
        static::assertSame($compute, $array['full_name']['compute']);
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

        static::assertArrayHasKey('display_name', $array);
        static::assertArrayNotHasKey('full_name', $array);
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

        static::assertArrayHasKey('email', $array);
        static::assertArrayNotHasKey('email_address', $array);
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

        static::assertSame(['name' => []], $array);
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

        static::assertArrayNotHasKey('accessor', $array['name']);
        static::assertArrayNotHasKey('compute', $array['name']);
        static::assertArrayNotHasKey('extras', $array['name']);
        static::assertArrayNotHasKey('guards', $array['name']);
        static::assertArrayNotHasKey('transformers', $array['name']);
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

        static::assertArrayHasKey('guards', $array['name']);
        static::assertSame([$guard], $array['name']['guards']);
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

        static::assertArrayHasKey('transformers', $array['name']);
        static::assertSame([$transformer], $array['name']['transformers']);
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

        static::assertArrayHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
        static::assertArrayHasKey('status', $result);
    }

    /**
     * Test that set overwrites earlier definitions with later ones.
     *
     * @return void
     */
    public function testSetOverwritesEarlierWithLater(): void
    {
        $accessor = fn ($resource) => 'computed';

        $result = Field::set(
            Field::scalar('name'),
            Field::accessor('name', $accessor),
        );

        static::assertSame($accessor, $result['name']['accessor']);
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

        static::assertArrayHasKey('name', $result);
        static::assertArrayHasKey('email', $result);
    }

    /**
     * Test static factory methods create Field instances.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\Field  $field
     * @param  string  $expectedKey
     * @return void
     */
    #[DataProvider('factoryMethodProvider')]
    public function testFactoryMethodsCreateFieldInstances(Field $field, string $expectedKey): void
    {
        $array = $field->toArray();

        static::assertArrayHasKey($expectedKey, $array);
    }

    /**
     * Provide factory method variations.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Http\Resources\Schema\Field, string}>
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
}
