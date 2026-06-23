<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
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
        static::assertInstanceOf(CompiledFieldDefinition::class, $organization);
        static::assertSame('organization', $organization->relation);
        static::assertSame(self::STUB_ORGANIZATION_RESOURCE, $organization->resource);

        $malformed = $schema->getField('malformed');
        static::assertInstanceOf(CompiledFieldDefinition::class, $malformed);
        static::assertNull($malformed->relation);
        static::assertNull($malformed->resource);
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

        static::assertSame(['email', 'name'], $schema->getFilterableColumns());
        static::assertSame(['email', 'created_at'], $schema->getSortableColumns());
        static::assertSame(['organization'], $schema->getTraversableRelations());
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

        static::assertSame([], $schema->getFilterableColumns());
        static::assertSame([], $schema->getSortableColumns());
        static::assertSame([], $schema->getTraversableRelations());
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

        static::assertSame([], $schema->getFilterableColumns());
        static::assertSame([], $schema->getSortableColumns());
        static::assertSame([], $schema->getTraversableRelations());
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

        static::assertSame(['status', 'email'], $schema->getFilterableColumns());
        static::assertSame(['status', 'created_at'], $schema->getSortableColumns());
        static::assertSame(['posts', 'tags'], $schema->getTraversableRelations());
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

        static::assertInstanceOf(CompiledSchema::class, $result);
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
        static::assertInstanceOf(CompiledFieldDefinition::class, $nameField);
        static::assertNull($nameField->relation);
        static::assertNull($nameField->compute);
        static::assertNull($nameField->accessor);

        $emailField = $schema->getField('email');
        static::assertInstanceOf(CompiledFieldDefinition::class, $emailField);
        static::assertSame('contact.email', $emailField->accessor);
        static::assertNull($emailField->relation);
        static::assertNull($emailField->compute);
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
        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame('organization', $field->relation);
        static::assertSame(self::STUB_ORGANIZATION_RESOURCE, $field->resource);
        static::assertSame(['name', 'slug'], $field->fields);
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

        static::assertSame([], $schema->getFieldKeys());

        $counts = $schema->getCountDefinitions();
        static::assertCount(1, $counts);
        static::assertArrayHasKey('posts', $counts);

        $count = $counts['posts'];
        static::assertInstanceOf(CompiledCountDefinition::class, $count);
        static::assertSame('posts', $count->presentKey);
        static::assertSame('posts', $count->relation);
        static::assertTrue($count->isDefault);
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

        static::assertSame($first, $second);
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

        static::assertNotSame($first, $second);
        static::assertInstanceOf(CompiledSchema::class, $second);
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

        static::assertSame([], $schema->getFieldKeys());
        static::assertSame([], $schema->getCountDefinitions());
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
        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame([$guard], $field->guards);
        static::assertSame([$transformer], $field->transformers);
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
        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame($fieldConstraint, $field->constraint);

        $counts = $schema->getCountDefinitions();
        static::assertSame($countConstraint, $counts['articles']->constraint);
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
            static::fail('Expected InvalidSchemaException to be thrown.');
        } catch (InvalidSchemaException $exception) {
            $errors = $exception->getErrors();
            static::assertCount(1, $errors);
            static::assertSame('organization', $errors[0]->fieldKey);
            static::assertSame('Constraint must be a Closure', $errors[0]->defect);
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
            static::fail('Expected InvalidSchemaException to be thrown.');
        } catch (InvalidSchemaException $exception) {
            $errors = $exception->getErrors();
            static::assertCount(1, $errors);
            static::assertSame('metric', $errors[0]->fieldKey);
            static::assertSame('Constraint must be a Closure', $errors[0]->defect);
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

        static::assertInstanceOf(CompiledSchema::class, $schema);
    }

    /**
     * Test that null and absent constraints compile without throwing.
     *
     * @return void
     */
    public function testCompileSucceedsForNullOrAbsentConstraints(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name'           => [],
            'organization'   => ['constraint' => null],
            '__count__:posts' => [
                'metric'   => 'count',
                'relation' => 'posts',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        static::assertInstanceOf(CompiledSchema::class, $schema);
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
        static::assertCount(1, $counts);
        static::assertArrayHasKey('comments', $counts);
        static::assertSame('comments', $counts['comments']->presentKey);
        static::assertSame('comments', $counts['comments']->relation);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $schema->getField('name'));
        static::assertArrayHasKey('posts', $schema->getCountDefinitions());
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

        static::assertArrayHasKey('published_posts', $counts);
        static::assertSame('published_posts', $counts['published_posts']->presentKey);
        static::assertSame('posts', $counts['published_posts']->relation);
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

        static::assertFalse($count->isDefault);
        static::assertSame([$guard], $count->guards);
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

        static::assertTrue($schema->getCountDefinitions()['likes']->isDefault);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame(['profile_id', 'user_id'], $field->needs);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame([], $field->needs);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame(['media'], $field->extras);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame($openApi, $field->openApi);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertNull($field->openApi);
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

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertNull($field->openApi);
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
                static::assertNotNull($openApi, "Timestamp field {$key} should auto-declare an openapi format");
                static::assertSame('string', $openApi->type);
                static::assertSame('date-time', $openApi->format);
            } else {
                static::assertNull($openApi, "Author-undeclared field {$key} should compile with a null openApi");
            }
        }
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
