<?php

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use Tests\TestCase;

/**
 * Tests for the FieldResolver field state and resolution class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldResolver::class)]
class FieldResolverTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver */
    private FieldResolver $resolver;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FieldResolver;
    }

    /**
     * Test that getFields returns default fields when no API query fields are
     * set.
     *
     * @return void
     */
    public function testGetFieldsReturnsDefaultFieldsWhenNoQueryFields(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', ['id']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', ['name', 'email'], []);

        static::assertSame(['name', 'email', 'id'], $result);
    }

    /**
     * Test that getFields returns API query fields when present.
     *
     * @return void
     */
    public function testGetFieldsReturnsQueryFieldsWhenPresent(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', []);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'status']);

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', ['name', 'email'], []);

        static::assertSame(['name', 'status'], $result);
    }

    /**
     * Test that getFields returns all field keys from the schema when
     * all-fields mode is enabled.
     *
     * @return void
     */
    public function testGetFieldsReturnsAllFieldKeysWhenAllMode(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', []);

        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        $schema = $this->createSchemaWithFieldKeys(['name', 'email', 'status', 'bio']);

        $result = $this->resolver->getFields($schema, 'users', ['name'], []);

        static::assertSame(['name', 'email', 'status', 'bio'], $result);
    }

    /**
     * Test that fields set via withoutFields are excluded from the result.
     *
     * @return void
     */
    public function testGetFieldsExcludesFieldsSetViaWithoutFields(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', []);

        $this->resolver->withoutFields(['email']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', ['name', 'email', 'status'], []);

        static::assertSame(['name', 'status'], $result);
    }

    /**
     * Test that fixed fields are always included in the result.
     *
     * @return void
     */
    public function testGetFieldsIncludesFixedFieldsAlways(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', ['id', '_type']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name']);

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', [], ['uuid']);

        static::assertSame(['name', 'id', '_type', 'uuid'], $result);
    }

    /**
     * Test that duplicate fields from merging are removed.
     *
     * @return void
     */
    public function testGetFieldsDeduplicatesResult(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', ['id']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['id', 'name']);

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', [], ['id']);

        static::assertSame(['id', 'name'], $result);
    }

    /**
     * Test that withFields overrides both default and query fields.
     *
     * @return void
     */
    public function testWithFieldsOverridesDefaultAndQueryFields(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', []);

        $this->resolver->withFields(['bio', 'avatar']);

        ApiQuery::shouldReceive('getFields')
            ->never();

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', ['name', 'email'], []);

        static::assertSame(['bio', 'avatar'], $result);
    }

    /**
     * Test that shouldRespondWithAll returns true when the all flag is set.
     *
     * @return void
     */
    public function testShouldRespondWithAllReturnsTrueWhenAllFlagSet(): void
    {

        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        static::assertTrue($this->resolver->shouldRespondWithAll('users'));
    }

    /**
     * Test that shouldRespondWithAll returns true when the API query contains
     * the :all token.
     *
     * @return void
     */
    public function testShouldRespondWithAllReturnsTrueWhenQueryContainsAll(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn([':all', 'name']);

        static::assertTrue($this->resolver->shouldRespondWithAll('users'));
    }

    /**
     * Test that shouldRespondWithAll returns false by default.
     *
     * @return void
     */
    public function testShouldRespondWithAllReturnsFalseByDefault(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        static::assertFalse($this->resolver->shouldRespondWithAll('users'));
    }

    /**
     * Test that shouldIncludeCountsField returns true when counts is in the
     * API query fields.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsTrueWhenCountsRequested(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'counts']);

        static::assertTrue($this->resolver->shouldIncludeCountsField('users', []));
    }

    /**
     * Test that shouldIncludeCountsField returns false when counts is
     * excluded via withoutFields.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsFalseWhenCountsExcluded(): void
    {

        $this->resolver->withoutFields(['counts']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'counts']);

        static::assertFalse($this->resolver->shouldIncludeCountsField('users', []));
    }

    /**
     * Test that shouldIncludeCountsField returns true when counts is in the
     * default fields and not excluded.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsTrueWhenInDefaultFields(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        static::assertTrue($this->resolver->shouldIncludeCountsField('users', ['name', 'counts']));
    }

    /**
     * Test that shouldIncludeCountsField returns true in all-fields mode
     * when counts is not excluded.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsTrueInAllMode(): void
    {

        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        static::assertTrue($this->resolver->shouldIncludeCountsField('users', []));
    }

    /**
     * Create a CompiledSchema with the given field keys using stub
     * definitions.
     *
     * @param  array<int, string>  $keys
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema
     */
    private function createSchemaWithFieldKeys(array $keys): CompiledSchema
    {

        $field = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: [],
        );

        $fields = [];

        foreach ($keys as $key) {
            $fields[$key] = $field;
        }

        return new CompiledSchema($fields, []);
    }
}
