<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the SchemaCompiler static compilation and caching class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaCompiler::class)]
final class SchemaCompilerTest extends TestCase
{
    /** @var string A stub resource class name used as a compiler fixture. */
    private const string STUB_ORGANIZATION_RESOURCE = 'App\Http\Resources\OrganizationResource';

    /**
     * Reset the schema compiler cache between tests.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that relation and resource entries are only extracted when they are
     * strings, and malformed values are normalized to null.
     *
     * NOTE: This test is intentionally declared first in the class. Mutations
     * to the isset/is_string narrowing in buildFieldDefinition emit PHP
     * warnings, which stop the mutation test run at the first defect; the first
     * executed test must therefore be the one that fails.
     *
     * @return void
     */
    public function testCompileNormalizesRelationAndResourceExtraction(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation' => 'organization',
                'resource' => self::STUB_ORGANIZATION_RESOURCE,
            ],
            'malformed' => [
                'relation' => 123,
                'resource' => 456,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $organization = $schema->getField('organization');
        self::assertInstanceOf(CompiledFieldDefinition::class, $organization);
        self::assertSame('organization', $organization->relation);
        self::assertSame(self::STUB_ORGANIZATION_RESOURCE, $organization->resource);

        $malformed = $schema->getField('malformed');
        self::assertInstanceOf(CompiledFieldDefinition::class, $malformed);
        self::assertNull($malformed->relation);
        self::assertNull($malformed->resource);
    }

    /**
     * Test that compile aggregates the declared filterable, sortable, and
     * traversable markers into the CompiledSchema query-surface sets,
     * de-duplicated and column-named.
     *
     * @return void
     */
    public function testCompileAggregatesQuerySurfaceSets(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'email'        => ['filterable' => 'email', 'sortable' => 'email'],
            'name'         => ['filterable' => 'name'],
            'created_at'   => ['sortable' => 'created_at'],
            'organization' => ['relation' => 'organization', 'traversable' => 'organization'],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame(['email', 'name'], $schema->getFilterableColumns());
        self::assertSame(['email', 'created_at'], $schema->getSortableColumns());
        self::assertSame(['organization'], $schema->getTraversableRelations());
    }

    /**
     * Test that a schema declaring no markers yields empty query-surface sets.
     *
     * @return void
     */
    public function testCompileYieldsEmptyQuerySurfaceWhenNothingDeclared(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'email' => [],
        ]));

        self::assertSame([], $schema->getFilterableColumns());
        self::assertSame([], $schema->getSortableColumns());
        self::assertSame([], $schema->getTraversableRelations());
    }

    /**
     * Test that query-surface markers that are present but not strings are
     * ignored: only a string marker contributes a column or relation.
     *
     * @return void
     */
    public function testCompileIgnoresNonStringQuerySurfaceMarkers(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'a' => ['filterable' => 123],
            'b' => ['sortable' => true],
            'c' => ['traversable' => ['organization']],
        ]));

        self::assertSame([], $schema->getFilterableColumns());
        self::assertSame([], $schema->getSortableColumns());
        self::assertSame([], $schema->getTraversableRelations());
    }

    /**
     * Test that duplicate markers are de-duplicated and the resulting sets are
     * reindexed to clean zero-based lists.
     *
     * The same column is declared on two fields before a distinct one, so
     * array_unique removes a non-trailing duplicate (leaving a key gap) and
     * array_values reindexes the result.
     *
     * @return void
     */
    public function testCompileDeduplicatesAndReindexesQuerySurfaceSets(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'status_a' => ['filterable' => 'status', 'sortable' => 'status', 'traversable' => 'posts'],
            'status_b' => ['filterable' => 'status', 'sortable' => 'status', 'traversable' => 'posts'],
            'email'    => ['filterable' => 'email', 'sortable' => 'created_at', 'traversable' => 'tags'],
        ]));

        self::assertSame(['status', 'email'], $schema->getFilterableColumns());
        self::assertSame(['status', 'created_at'], $schema->getSortableColumns());
        self::assertSame(['posts', 'tags'], $schema->getTraversableRelations());
    }

    /**
     * Test that compile returns a CompiledSchema from a raw schema array.
     *
     * @return void
     */
    public function testCompileReturnsCompiledSchemaFromRawSchema(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name' => [],
        ]);

        $result = SchemaCompiler::compile($resourceClass);

        self::assertInstanceOf(CompiledSchema::class, $result);
    }

    /**
     * Test that scalar fields produce CompiledFieldDefinition with null
     * relation and null compute.
     *
     * @return void
     */
    public function testCompileCreatesCompiledFieldDefinitionsForScalarFields(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name'  => [],
            'email' => ['accessor' => 'contact.email'],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $nameField = $schema->getField('name');
        self::assertInstanceOf(CompiledFieldDefinition::class, $nameField);
        self::assertNull($nameField->relation);
        self::assertNull($nameField->compute);
        self::assertNull($nameField->accessor);

        $emailField = $schema->getField('email');
        self::assertInstanceOf(CompiledFieldDefinition::class, $emailField);
        self::assertSame('contact.email', $emailField->accessor);
        self::assertNull($emailField->relation);
        self::assertNull($emailField->compute);
    }

    /**
     * Test that relation fields produce CompiledFieldDefinition with relation
     * and resource populated.
     *
     * @return void
     */
    public function testCompileCreatesCompiledFieldDefinitionsForRelationFields(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation' => 'organization',
                'resource' => self::STUB_ORGANIZATION_RESOURCE,
                'fields'   => ['name', 'slug'],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $field = $schema->getField('organization');
        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame('organization', $field->relation);
        self::assertSame(self::STUB_ORGANIZATION_RESOURCE, $field->resource);
        self::assertSame(['name', 'slug'], $field->fields);
    }

    /**
     * Test that count entries produce CompiledCountDefinition with the correct
     * present key.
     *
     * @return void
     */
    public function testCompileCreatesCompiledCountDefinitionsForCountMetrics(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:posts' => [
                'key'      => 'posts',
                'metric'   => 'count',
                'relation' => 'posts',
                'default'  => true,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame([], $schema->getFieldKeys());

        $counts = $schema->getCountDefinitions();
        self::assertCount(1, $counts);
        self::assertArrayHasKey('posts', $counts);

        $count = $counts['posts'];
        self::assertInstanceOf(CompiledCountDefinition::class, $count);
        self::assertSame('posts', $count->presentKey);
        self::assertSame('posts', $count->relation);
        self::assertTrue($count->isDefault);
    }

    /**
     * Test that calling compile twice returns the same cached instance.
     *
     * @return void
     */
    public function testCompileCachesResultForSameResourceClass(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'id' => [],
        ]);

        $first  = SchemaCompiler::compile($resourceClass);
        $second = SchemaCompiler::compile($resourceClass);

        self::assertSame($first, $second);
    }

    /**
     * Test that clearCache forces a fresh compilation on subsequent calls.
     *
     * @return void
     */
    public function testClearCacheRemovesCachedSchemas(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'id' => [],
        ]);

        $first = SchemaCompiler::compile($resourceClass);

        SchemaCompiler::clearCache();

        $second = SchemaCompiler::compile($resourceClass);

        self::assertNotSame($first, $second);
        self::assertInstanceOf(CompiledSchema::class, $second);
    }

    /**
     * Test that an empty schema produces a CompiledSchema with no fields and no
     * counts.
     *
     * @return void
     */
    public function testCompileHandlesEmptySchema(): void
    {
        $resourceClass = $this->createStubResourceClass([]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame([], $schema->getFieldKeys());
        self::assertSame([], $schema->getCountDefinitions());
    }

    /**
     * Test that guards and transformers from the raw schema appear in the
     * compiled definitions.
     *
     * @return void
     */
    public function testCompilePreservesGuardsAndTransformers(): void
    {
        $guard       = fn ($resource, $request) => true;
        $transformer = fn ($resource, $value) => strtoupper($value);

        $resourceClass = $this->createStubResourceClass([
            'secret' => [
                'guards'       => [$guard],
                'transformers' => [$transformer],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $field = $schema->getField('secret');
        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame([$guard], $field->guards);
        self::assertSame([$transformer], $field->transformers);
    }

    /**
     * Test that Closure constraints appear in both compiled field and count
     * definitions.
     *
     * @return void
     */
    public function testCompilePreservesConstraintClosures(): void
    {
        $fieldConstraint = fn ($query) => $query->where('active', true);
        $countConstraint = fn ($query) => $query->where('published', true);

        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation'   => 'organization',
                'resource'   => self::STUB_ORGANIZATION_RESOURCE,
                'constraint' => $fieldConstraint,
            ],
            '__count__:articles' => [
                'key'        => 'articles',
                'metric'     => 'count',
                'relation'   => 'articles',
                'constraint' => $countConstraint,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $field = $schema->getField('organization');
        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame($fieldConstraint, $field->constraint);

        $counts = $schema->getCountDefinitions();
        self::assertSame($countConstraint, $counts['articles']->constraint);
    }

    /**
     * Test that a non-Closure field constraint causes compile to throw an
     * InvalidSchemaException reporting the defect.
     *
     * @return void
     */
    public function testCompileThrowsForNonClosureFieldConstraint(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation'   => 'organization',
                'resource'   => self::STUB_ORGANIZATION_RESOURCE,
                'constraint' => 'not-a-closure',
            ],
        ]);

        try {
            SchemaCompiler::compile($resourceClass);
            self::fail('Expected InvalidSchemaException to be thrown.');
        } catch (InvalidSchemaException $exception) {
            $errors = $exception->getErrors();
            self::assertCount(1, $errors);
            self::assertSame('organization', $errors[0]->fieldKey);
            self::assertSame('Constraint must be a Closure', $errors[0]->defect);
        }
    }

    /**
     * Test that a non-Closure count constraint causes compile to throw an
     * InvalidSchemaException reporting the defect.
     *
     * @return void
     */
    public function testCompileThrowsForNonClosureCountConstraint(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'metric' => [
                'metric'     => 'count',
                'relation'   => 'posts',
                'constraint' => 'not-a-closure',
            ],
        ]);

        try {
            SchemaCompiler::compile($resourceClass);
            self::fail('Expected InvalidSchemaException to be thrown.');
        } catch (InvalidSchemaException $exception) {
            $errors = $exception->getErrors();
            self::assertCount(1, $errors);
            self::assertSame('metric', $errors[0]->fieldKey);
            self::assertSame('Constraint must be a Closure', $errors[0]->defect);
        }
    }

    /**
     * Test that Closure constraints on both field and count definitions compile
     * without throwing.
     *
     * @return void
     */
    public function testCompileSucceedsForClosureConstraints(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation'   => 'organization',
                'resource'   => self::STUB_ORGANIZATION_RESOURCE,
                'constraint' => fn ($query) => $query,
            ],
            '__count__:articles' => [
                'metric'     => 'count',
                'relation'   => 'articles',
                'constraint' => fn ($query) => $query,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertInstanceOf(CompiledSchema::class, $schema);
    }

    /**
     * Test that null and absent constraints compile without throwing.
     *
     * @return void
     */
    public function testCompileSucceedsForNullOrAbsentConstraints(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name'            => [],
            'organization'    => ['constraint' => null],
            '__count__:posts' => [
                'metric'   => 'count',
                'relation' => 'posts',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertInstanceOf(CompiledSchema::class, $schema);
    }

    /**
     * Test that a count with a __count__: prefix is normalized to the correct
     * present key when no explicit key is provided.
     *
     * @return void
     */
    public function testCompileHandlesCountWithPrefixedKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:comments' => [
                'metric'   => 'count',
                'relation' => 'comments',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $counts = $schema->getCountDefinitions();
        self::assertCount(1, $counts);
        self::assertArrayHasKey('comments', $counts);
        self::assertSame('comments', $counts['comments']->presentKey);
        self::assertSame('comments', $counts['comments']->relation);
    }

    /**
     * Test that field entries declared after a count entry are still compiled.
     *
     * @return void
     */
    public function testCompileProcessesFieldsDeclaredAfterCountEntries(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:posts' => [
                'metric'   => 'count',
                'relation' => 'posts',
            ],
            'name' => [],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertInstanceOf(CompiledFieldDefinition::class, $schema->getField('name'));
        self::assertArrayHasKey('posts', $schema->getCountDefinitions());
    }

    /**
     * Test that an explicit count key overrides the prefix-stripped schema key
     * while the relation is taken from the definition.
     *
     * @return void
     */
    public function testCompileUsesExplicitCountKeyOverSchemaKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:posts' => [
                'key'      => 'published_posts',
                'metric'   => 'count',
                'relation' => 'posts',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $counts = $schema->getCountDefinitions();

        self::assertArrayHasKey('published_posts', $counts);
        self::assertSame('published_posts', $counts['published_posts']->presentKey);
        self::assertSame('posts', $counts['published_posts']->relation);
    }

    /**
     * Test that counts without a default flag compile as non-default and that
     * count guards are preserved.
     *
     * @return void
     */
    public function testCompileDefaultsCountToNonDefaultAndPreservesGuards(): void
    {
        $guard = fn ($resource, $request) => true;

        $resourceClass = $this->createStubResourceClass([
            '__count__:comments' => [
                'metric'   => 'count',
                'relation' => 'comments',
                'guards'   => [$guard],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $count  = $schema->getCountDefinitions()['comments'];

        self::assertFalse($count->isDefault);
        self::assertSame([$guard], $count->guards);
    }

    /**
     * Test that a truthy non-boolean count default is normalized to a real
     * boolean.
     *
     * @return void
     */
    public function testCompileCastsTruthyCountDefaultToBoolean(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:likes' => [
                'metric'   => 'count',
                'relation' => 'likes',
                'default'  => ['truthy'],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertTrue($schema->getCountDefinitions()['likes']->isDefault);
    }

    /**
     * Test that a needs declaration is threaded through to the compiled field
     * definition.
     *
     * @return void
     */
    public function testCompilePreservesNeedsColumns(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'profile' => [
                'needs' => ['profile_id', 'user_id'],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('profile');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame(['profile_id', 'user_id'], $field->needs);
    }

    /**
     * Test that a field without a needs declaration compiles to an empty needs
     * array.
     *
     * @return void
     */
    public function testCompileDefaultsNeedsToEmptyArrayWhenAbsent(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name' => [],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame([], $field->needs);
    }

    /**
     * Test that a scalar extras entry is normalized to an array on the compiled
     * field definition.
     *
     * @return void
     */
    public function testCompileNormalizesExtrasToArray(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'avatar' => [
                'extras' => 'media',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('avatar');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame(['media'], $field->extras);
    }

    /**
     * Test that an openapi schema is threaded into the compiled field
     * definition's openApi property.
     *
     * @return void
     */
    public function testCompileThreadsOpenApiSchemaIntoFieldDefinition(): void
    {
        $openApi = new OpenApiFieldSchema(type: 'string', format: 'email');

        $resourceClass = $this->createStubResourceClass([
            'email' => [
                'openapi' => $openApi,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('email');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame($openApi, $field->openApi);
    }

    /**
     * Test that a field with no openapi key compiles with a null openApi
     * property.
     *
     * @return void
     */
    public function testCompileLeavesOpenApiNullWhenAbsent(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name' => [],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertNull($field->openApi);
    }

    /**
     * Test that a malformed (non-schema) openapi value is normalized to null.
     *
     * @return void
     */
    public function testCompileNormalizesMalformedOpenApiToNull(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name' => [
                'openapi' => 'not-a-schema',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertNull($field->openApi);
    }

    /**
     * Test that a real fixture resource carries no author-declared openapi on
     * its plain scalar/compute/relation fields, while the toolkit's own
     * timestamp() factory auto-declares a date-time format.
     *
     * This is the resource-level backward-compatibility oracle (AC-11): a field
     * the author did not annotate compiles with a null openApi, so the new
     * declaration is absent from every author-required call path. The
     * timestamp() factory's built-in date-time format (AC-07) is the only
     * auto-declaration and it never affects runtime serialization (the runtime
     * path ignores openapi).
     *
     * @return void
     */
    public function testRealResourceCompilesScalarsNullAndTimestampsWithDateTimeFormat(): void
    {
        $schema = SchemaCompiler::compile(UserResource::class);

        foreach ($schema->getFieldKeys() as $key) {
            $openApi = $schema->getField($key)?->openApi;

            if (in_array($key, ['created_at', 'updated_at'], true)) {
                self::assertNotNull($openApi, "Timestamp field {$key} should auto-declare an openapi format");
                self::assertSame('string', $openApi->type);
                self::assertSame('date-time', $openApi->format);
            } else {
                self::assertNull($openApi, "Author-undeclared field {$key} should compile with a null openApi");
            }
        }
    }

    /**
     * Test that sum entries produce a CompiledAggregateDefinition for the 'sum'
     * metric with the correct present key.
     *
     * @return void
     */
    public function testCompileCreatesCompiledAggregateDefinitionsForSumMetrics(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__sum__:posts_id' => [
                'key'      => 'posts_id',
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
                'default'  => true,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame([], $schema->getFieldKeys());
        self::assertSame([], $schema->getCountDefinitions());

        $aggregates = $schema->getAggregateDefinitions();
        self::assertCount(1, $aggregates);
        self::assertArrayHasKey('sum:posts_id', $aggregates);

        $agg = $aggregates['sum:posts_id'];
        self::assertInstanceOf(CompiledAggregateDefinition::class, $agg);
        self::assertSame('posts_id', $agg->presentKey);
        self::assertSame('posts', $agg->relation);
        self::assertSame('id', $agg->column);
        self::assertSame('sum', $agg->metric);
        self::assertTrue($agg->isDefault);
    }

    /**
     * Test that average entries produce CompiledAggregateDefinition with
     * metric='avg' and the correct present key.
     *
     * @return void
     */
    public function testCompileCreatesCompiledAggregateDefinitionsForAvgMetrics(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__avg__:posts_id' => [
                'key'      => 'posts_id',
                'metric'   => 'avg',
                'relation' => 'posts',
                'column'   => 'id',
                'default'  => false,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $aggregates = $schema->getAggregateDefinitions();
        self::assertCount(1, $aggregates);

        $agg = $aggregates['avg:posts_id'];
        self::assertInstanceOf(CompiledAggregateDefinition::class, $agg);
        self::assertSame('avg', $agg->metric);
        self::assertFalse($agg->isDefault);
    }

    /**
     * Test that an explicit key in a sum entry overrides the prefix-stripped
     * schema key and the relation is taken from the definition.
     *
     * @return void
     */
    public function testCompileUsesExplicitAggregateKeyOverSchemaKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__sum__:posts_id' => [
                'key'      => 'my_sum',
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('sum:my_sum', $aggregates);
        self::assertSame('my_sum', $aggregates['sum:my_sum']->presentKey);
        self::assertSame('posts', $aggregates['sum:my_sum']->relation);
    }

    /**
     * Test that an aggregate without a default flag compiles as non-default and
     * that guards are preserved.
     *
     * @return void
     */
    public function testCompileDefaultsAggregateToNonDefaultAndPreservesGuards(): void
    {
        $guard = fn ($resource, $request) => true;

        $resourceClass = $this->createStubResourceClass([
            '__sum__:posts_id' => [
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
                'guards'   => [$guard],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $agg    = $schema->getAggregateDefinitions()['sum:posts_id'];

        self::assertFalse($agg->isDefault);
        self::assertSame([$guard], $agg->guards);
    }

    /**
     * Test that a non-Closure aggregate constraint throws an
     * InvalidSchemaException.
     *
     * @return void
     */
    public function testCompileThrowsForNonClosureAggregateConstraint(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__sum__:posts_id' => [
                'metric'     => 'sum',
                'relation'   => 'posts',
                'column'     => 'id',
                'constraint' => 'not-a-closure',
            ],
        ]);

        try {
            SchemaCompiler::compile($resourceClass);
            self::fail('Expected InvalidSchemaException to be thrown.');
        } catch (InvalidSchemaException $exception) {
            $errors = $exception->getErrors();
            self::assertCount(1, $errors);
            self::assertSame('Constraint must be a Closure', $errors[0]->defect);
        }
    }

    /**
     * Test that an empty schema produces no aggregate definitions.
     *
     * @return void
     */
    public function testCompileHandlesEmptySchemaProducesNoAggregates(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([]));

        self::assertSame([], $schema->getAggregateDefinitions());
    }

    /**
     * Test that constraint validation continues past valid entries to report
     * errors on later entries with an invalid constraint.
     *
     * @return void
     */
    public function testCompileValidationContinuesPastValidConstraintsToFindInvalidOnes(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name'             => [],
            '__sum__:posts_id' => [
                'metric'     => 'sum',
                'relation'   => 'posts',
                'column'     => 'id',
                'constraint' => 'not-a-closure',
            ],
        ]);

        $this->expectException(InvalidSchemaException::class);

        SchemaCompiler::compile($resourceClass);
    }

    /**
     * Test that schema entries declared after a sum entry are still compiled
     * into the field definitions.
     *
     * @return void
     */
    public function testCompileProcessesFieldsDeclaredAfterSumEntry(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__sum__:posts_id' => [
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
                'default'  => true,
            ],
            'name' => [],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertNotEmpty($schema->getAggregateDefinitions());
        self::assertNotNull($schema->getField('name'), '\'name\' field must be compiled when declared after a sum entry');
    }

    /**
     * Test that a non-prefixed aggregate schema key is used verbatim as the
     * present key when no explicit key override is provided.
     *
     * @return void
     */
    public function testCompilePreservesNonPrefixedAggregateSchemaKeyAsPresentKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'my_sum_key' => [
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('sum:my_sum_key', $aggregates, 'non-prefixed schema key must become the presentKey as-is');
        self::assertSame('my_sum_key', $aggregates['sum:my_sum_key']->presentKey);
    }

    /**
     * Test that the __avg__: prefix is stripped from the schema key when no
     * explicit key override is provided.
     *
     * @return void
     */
    public function testCompileStripsAvgPrefixWhenNoExplicitKeyProvided(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__avg__:comments_id' => [
                'metric'   => 'avg',
                'relation' => 'comments',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('avg:comments_id', $aggregates, '__avg__: prefix must be stripped to derive the presentKey');
        self::assertSame('comments_id', $aggregates['avg:comments_id']->presentKey);
    }

    /**
     * Test that the __sum__: prefix is stripped from the schema key when no
     * explicit key override is provided.
     *
     * @return void
     */
    public function testCompileStripsSumPrefixWhenNoExplicitKeyProvided(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__sum__:comments_id' => [
                'metric'   => 'sum',
                'relation' => 'comments',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('sum:comments_id', $aggregates, '__sum__: prefix must be stripped to derive the presentKey');
        self::assertSame('comments_id', $aggregates['sum:comments_id']->presentKey);
    }

    /**
     * Test that a Closure constraint on an aggregate definition is preserved
     * through to the compiled definition.
     *
     * @return void
     */
    public function testCompilePreservesAggregateClosureConstraint(): void
    {
        $constraint = fn ($query) => $query->where('active', true);

        $resourceClass = $this->createStubResourceClass([
            '__sum__:posts_id' => [
                'metric'     => 'sum',
                'relation'   => 'posts',
                'column'     => 'id',
                'constraint' => $constraint,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $agg    = $schema->getAggregateDefinitions()['sum:posts_id'];

        self::assertSame($constraint, $agg->constraint);
    }

    /**
     * Create an anonymous class with a static schema() method returning the
     * given raw schema.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return class-string
     */
    private function createStubResourceClass(array $rawSchema): string
    {
        $stub = new class ($rawSchema) {
            /** @var array<string, array<string, mixed>> */
            private static array $schema = [];

            /**
             * @param  array<string, array<string, mixed>>  $schema
             */
            public function __construct(array $schema)
            {
                self::$schema = $schema;
            }

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return self::$schema;
            }
        };

        return $stub::class;
    }
}
