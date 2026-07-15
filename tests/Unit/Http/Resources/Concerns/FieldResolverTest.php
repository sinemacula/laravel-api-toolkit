<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use Tests\Concerns\InteractsWithNonPublicMembers;
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
final class FieldResolverTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver */
    private FieldResolver $resolver;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FieldResolver;
    }

    /**
     * Test that the assembled field list is memoised across resolver instances,
     * so a homogeneous collection assembles it once rather than per row.
     *
     * @return void
     */
    public function testGetFieldsMemoisesTheAssembledListAcrossInstances(): void
    {
        config()->set('api-toolkit.resources.fixed_fields', []);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'email']);

        $schema = new CompiledSchema([], []);

        $first = (new FieldResolver)->getFields($schema, 'users', ['name', 'email'], []);

        // Tamper the single cached entry so a second resolver with identical
        // inputs can only return the assembled list if it reads the memo.
        /** @var array<string, array<int, string>> $cache */
        $cache = $this->getStaticProperty(FieldResolver::class, 'resolvedCache');
        $this->setStaticProperty(FieldResolver::class, 'resolvedCache', [array_key_first($cache) => ['sentinel']]);

        $second = (new FieldResolver)->getFields($schema, 'users', ['name', 'email'], []);

        self::assertSame(['name', 'email'], $first);
        self::assertSame(['sentinel'], $second);
    }

    /**
     * Test that excluded fields form part of the memo key, so a resolver that
     * excludes a field is never handed a list cached for one that kept it.
     *
     * @return void
     */
    public function testExcludedFieldsAreDistinguishedInTheMemo(): void
    {
        config()->set('api-toolkit.resources.fixed_fields', []);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        $schema = new CompiledSchema([], []);

        $full = (new FieldResolver)->getFields($schema, 'users', ['name', 'email'], []);

        $resolver = new FieldResolver;
        $resolver->withoutFields(['email']);
        $trimmed = $resolver->getFields($schema, 'users', ['name', 'email'], []);

        self::assertSame(['name', 'email'], $full);
        self::assertSame(['name'], $trimmed);
    }

    /**
     * Test that the resolved base fields form part of the memo key, so two
     * resolvers with different field sets never share a cached list.
     *
     * @return void
     */
    public function testBaseFieldsAreDistinguishedInTheMemo(): void
    {
        config()->set('api-toolkit.resources.fixed_fields', []);

        ApiQuery::shouldReceive('getFields')->andReturn(null);

        $schema = new CompiledSchema([], []);

        $name  = (new FieldResolver)->getFields($schema, 'users', ['name'], []);
        $title = (new FieldResolver)->getFields($schema, 'users', ['title'], []);

        self::assertSame(['name'], $name);
        self::assertSame(['title'], $title);
    }

    /**
     * Test that the configured fixed fields form part of the memo key, so a
     * change to the fixed-field config is reflected in the assembled list.
     *
     * @return void
     */
    public function testConfigFixedFieldsAreDistinguishedInTheMemo(): void
    {
        ApiQuery::shouldReceive('getFields')->with('users')->andReturn(null);

        $schema = new CompiledSchema([], []);

        config()->set('api-toolkit.resources.fixed_fields', ['id']);
        $withId = (new FieldResolver)->getFields($schema, 'users', ['name'], []);

        config()->set('api-toolkit.resources.fixed_fields', ['uuid']);
        $withUuid = (new FieldResolver)->getFields($schema, 'users', ['name'], []);

        self::assertSame(['name', 'id'], $withId);
        self::assertSame(['name', 'uuid'], $withUuid);
    }

    /**
     * Test that the per-resource fixed fields form part of the memo key, so a
     * resource contributing its own fixed field is not handed a cached list
     * assembled without it.
     *
     * @return void
     */
    public function testFixedFieldParameterIsDistinguishedInTheMemo(): void
    {
        config()->set('api-toolkit.resources.fixed_fields', []);

        ApiQuery::shouldReceive('getFields')->with('users')->andReturn(null);

        $schema = new CompiledSchema([], []);

        $plain    = (new FieldResolver)->getFields($schema, 'users', ['name'], []);
        $withUuid = (new FieldResolver)->getFields($schema, 'users', ['name'], ['uuid']);

        self::assertSame(['name'], $plain);
        self::assertSame(['name', 'uuid'], $withUuid);
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

        self::assertSame(['name', 'email', 'id'], $result);
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

        self::assertSame(['name', 'status'], $result);
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

        self::assertSame(['name', 'email', 'status', 'bio'], $result);
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

        self::assertSame(['name', 'status'], $result);
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

        self::assertSame(['name', 'id', '_type', 'uuid'], $result);
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

        self::assertSame(['id', 'name'], $result);
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

        self::assertSame(['bio', 'avatar'], $result);
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

        self::assertTrue($this->resolver->shouldRespondWithAll('users'));
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

        self::assertTrue($this->resolver->shouldRespondWithAll('users'));
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

        self::assertFalse($this->resolver->shouldRespondWithAll('users'));
    }

    /**
     * Test that shouldIncludeCountsField returns true when counts is in the API
     * query fields.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsTrueWhenCountsRequested(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'counts']);

        self::assertTrue($this->resolver->shouldIncludeCountsField('users', []));
    }

    /**
     * Test that shouldIncludeCountsField returns false when counts is excluded
     * via withoutFields.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsFalseWhenCountsExcluded(): void
    {

        $this->resolver->withoutFields(['counts']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'counts']);

        self::assertFalse($this->resolver->shouldIncludeCountsField('users', []));
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

        self::assertTrue($this->resolver->shouldIncludeCountsField('users', ['name', 'counts']));
    }

    /**
     * Test that shouldIncludeCountsField returns true in all-fields mode when
     * counts is not excluded.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsTrueInAllMode(): void
    {

        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertTrue($this->resolver->shouldIncludeCountsField('users', []));
    }

    /**
     * Test that getFields returns a sequentially indexed list after
     * deduplication removes an interior duplicate.
     *
     * @return void
     */
    public function testGetFieldsReindexesAfterDeduplication(): void
    {

        config()->set('api-toolkit.resources.fixed_fields', []);

        $this->resolver->withFields(['name', 'id', 'name', 'email']);

        $schema = new CompiledSchema([], []);

        $result = $this->resolver->getFields($schema, 'users', [], []);

        self::assertSame(['name', 'id', 'email'], $result);
    }

    /**
     * Test that shouldIncludeCountsField returns false when the requested
     * fields neither contain counts nor the :all token.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsFalseWhenRequestedFieldsLackCounts(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name']);

        self::assertFalse($this->resolver->shouldIncludeCountsField('users', []));
    }

    /**
     * Test that shouldIncludeCountsField returns false when counts is absent
     * from the default fields and nothing was requested.
     *
     * @return void
     */
    public function testShouldIncludeCountsFieldReturnsFalseWhenDefaultsLackCounts(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertFalse($this->resolver->shouldIncludeCountsField('users', ['name']));
    }

    /**
     * Test that shouldIncludeSumsField returns true when sums is in the API
     * query fields.
     *
     * @return void
     */
    public function testShouldIncludeSumsFieldReturnsTrueWhenSumsRequested(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'sums']);

        self::assertTrue($this->resolver->shouldIncludeSumsField('users', []));
    }

    /**
     * Test that shouldIncludeSumsField returns false when sums is excluded via
     * withoutFields.
     *
     * @return void
     */
    public function testShouldIncludeSumsFieldReturnsFalseWhenSumsExcluded(): void
    {

        $this->resolver->withoutFields(['sums']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'sums']);

        self::assertFalse($this->resolver->shouldIncludeSumsField('users', []));
    }

    /**
     * Test that shouldIncludeSumsField returns true when sums is in the default
     * fields and not excluded.
     *
     * @return void
     */
    public function testShouldIncludeSumsFieldReturnsTrueWhenInDefaultFields(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertTrue($this->resolver->shouldIncludeSumsField('users', ['name', 'sums']));
    }

    /**
     * Test that shouldIncludeSumsField returns true in all-fields mode.
     *
     * @return void
     */
    public function testShouldIncludeSumsFieldReturnsTrueInAllMode(): void
    {

        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertTrue($this->resolver->shouldIncludeSumsField('users', []));
    }

    /**
     * Test that shouldIncludeSumsField returns false when requested fields lack
     * sums and it is not in defaults.
     *
     * @return void
     */
    public function testShouldIncludeSumsFieldReturnsFalseWhenNotRequestedAndNotInDefaults(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name']);

        self::assertFalse($this->resolver->shouldIncludeSumsField('users', []));
    }

    /**
     * Test that shouldIncludeAveragesField returns true when averages is in the
     * API query fields.
     *
     * @return void
     */
    public function testShouldIncludeAveragesFieldReturnsTrueWhenAveragesRequested(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'averages']);

        self::assertTrue($this->resolver->shouldIncludeAveragesField('users', []));
    }

    /**
     * Test that shouldIncludeAveragesField returns false when averages is
     * excluded via withoutFields.
     *
     * @return void
     */
    public function testShouldIncludeAveragesFieldReturnsFalseWhenAveragesExcluded(): void
    {

        $this->resolver->withoutFields(['averages']);

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name', 'averages']);

        self::assertFalse($this->resolver->shouldIncludeAveragesField('users', []));
    }

    /**
     * Test that shouldIncludeAveragesField returns true when averages is in the
     * default fields and not excluded.
     *
     * @return void
     */
    public function testShouldIncludeAveragesFieldReturnsTrueWhenInDefaultFields(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertTrue($this->resolver->shouldIncludeAveragesField('users', ['name', 'averages']));
    }

    /**
     * Test that shouldIncludeAveragesField returns true in all-fields mode.
     *
     * @return void
     */
    public function testShouldIncludeAveragesFieldReturnsTrueInAllMode(): void
    {

        $this->resolver->withAll();

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(null);

        self::assertTrue($this->resolver->shouldIncludeAveragesField('users', []));
    }

    /**
     * Test that shouldIncludeAveragesField returns false when not requested and
     * absent from defaults.
     *
     * @return void
     */
    public function testShouldIncludeAveragesFieldReturnsFalseWhenNotRequestedAndNotInDefaults(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['name']);

        self::assertFalse($this->resolver->shouldIncludeAveragesField('users', []));
    }

    /**
     * Create a CompiledSchema with the given field keys using stub definitions.
     *
     * @param  array<int, string>  $keys
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
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
            needs: [],
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
