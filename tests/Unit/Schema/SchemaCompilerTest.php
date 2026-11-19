<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Average;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
use SineMacula\ApiToolkit\Schema\Min;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Sum;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the SchemaCompiler.
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
     * Test that non-string relation and resource entries are normalized to
     * null.
     *
     * Declared first because mutations to the isset/is_string narrowing emit
     * PHP warnings that halt the mutation run at the first defect.
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
     * Test that compile collects the filterable, sortable and traversable
     * markers into de-duplicated, column-named sets, keying each filterable
     * column to its capability.
     *
     * @return void
     */
    public function testCompileAggregatesQuerySurfaceSets(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'email'        => ['filterable' => 'email', 'capability' => Capability::EXACT, 'sortable' => 'email'],
            'name'         => ['filterable' => 'name', 'capability' => Capability::ENUM],
            'created_at'   => ['sortable' => 'created_at'],
            'organization' => ['relation' => 'organization', 'traversable' => 'organization'],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame(['email' => Capability::EXACT, 'name' => Capability::ENUM], $schema->getFilterableColumns());
        self::assertSame(['email', 'created_at'], $schema->getSortableColumns());
        self::assertSame(['organization'], $schema->getTraversableRelations());

        $name = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $name);
        self::assertSame('name', $name->filterable);
        self::assertSame(Capability::ENUM, $name->filterCapability);
    }

    /**
     * Provide schemas declaring a traversable accessor-only alias beside a
     * sibling declaring the resource, in both orders.
     *
     * @return iterable<string, array{array<string, array<string, mixed>>}>
     */
    public static function traversableResourceProvider(): iterable
    {
        $alias    = ['relation' => 'organization', 'accessor' => 'name', 'traversable' => 'organization'];
        $resource = ['relation' => 'organization', 'resource' => self::STUB_ORGANIZATION_RESOURCE];
        $others   = [
            'profile' => ['relation' => 'profile', 'accessor' => 'bio', 'traversable' => 'profile'],
            'posts'   => ['relation' => 'posts', 'resource' => self::STUB_ORGANIZATION_RESOURCE],
        ];

        yield 'alias first' => [['organization_name' => $alias, 'organization' => $resource, ...$others]];
        yield 'resource first' => [['organization' => $resource, 'organization_name' => $alias, ...$others]];
    }

    /**
     * Test that a traversable relation resolves to the resource any field over
     * it declares, whichever is declared first, and an accessor-only one to
     * none.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return void
     */
    #[DataProvider('traversableResourceProvider')]
    public function testCompileRecordsTheResourceDeclaredOverEachTraversableRelation(array $schema): void
    {
        $compiled = SchemaCompiler::compile($this->createStubResourceClass($schema));

        self::assertSame(self::STUB_ORGANIZATION_RESOURCE, $compiled->getTraversableResource('organization'));
        self::assertNull($compiled->getTraversableResource('profile'));
        self::assertNull($compiled->getTraversableResource('posts'));
    }

    /**
     * Test that a filterable column without a real capability is left out of
     * the filter surface but stays on the field for validation to report.
     *
     * @return void
     */
    public function testCompileDropsAFilterableColumnDeclaredWithoutACapability(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'email'  => ['filterable' => 'email'],
            'status' => ['filterable' => 'status', 'capability' => 'enum'],
            'name'   => ['filterable' => 'name', 'capability' => Capability::EXACT],
        ]));

        self::assertSame(['name' => Capability::EXACT], $schema->getFilterableColumns());

        $email = $schema->getField('email');

        self::assertInstanceOf(CompiledFieldDefinition::class, $email);
        self::assertSame('email', $email->filterable);
        self::assertNull($email->filterCapability);

        $status = $schema->getField('status');

        self::assertInstanceOf(CompiledFieldDefinition::class, $status);
        self::assertNull($status->filterCapability);
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
            'a' => ['filterable' => 123, 'capability' => Capability::EXACT],
            'b' => ['sortable' => true],
            'c' => ['traversable' => ['organization']],
        ]));

        self::assertSame([], $schema->getFilterableColumns());
        self::assertSame([], $schema->getSortableColumns());
        self::assertSame([], $schema->getTraversableRelations());
    }

    /**
     * Test that duplicate markers are de-duplicated into zero-based lists and a
     * column declared filterable twice is keyed once.
     *
     * A non-trailing duplicate leaves a key gap, so the reindex is observable.
     *
     * @return void
     */
    public function testCompileDeduplicatesAndReindexesQuerySurfaceSets(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'status_a' => ['filterable' => 'status', 'capability' => Capability::EXACT, 'sortable' => 'status', 'traversable' => 'posts'],
            'status_b' => ['filterable' => 'status', 'capability' => Capability::EXACT, 'sortable' => 'status', 'traversable' => 'posts'],
            'email'    => ['filterable' => 'email', 'capability' => Capability::EXACT, 'sortable' => 'created_at', 'traversable' => 'tags'],
        ]));

        self::assertSame(['status' => Capability::EXACT, 'email' => Capability::EXACT], $schema->getFilterableColumns());
        self::assertSame(['status', 'created_at'], $schema->getSortableColumns());
        self::assertSame(['posts', 'tags'], $schema->getTraversableRelations());
    }

    /**
     * Test that compile carries the index overrides declared behind a sortable
     * column onto the compiled field.
     *
     * @return void
     */
    public function testCompileCarriesTheIndexOverridesOntoTheField(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'name'       => ['sortable' => 'name', 'indexed' => 'users_lower_name_index'],
            'created_at' => ['sortable' => 'created_at', 'unindexed' => 'Bounded table'],
            'email'      => ['sortable' => 'email'],
        ]));

        $name      = $schema->getField('name');
        $createdAt = $schema->getField('created_at');
        $email     = $schema->getField('email');

        self::assertInstanceOf(CompiledFieldDefinition::class, $name);
        self::assertInstanceOf(CompiledFieldDefinition::class, $createdAt);
        self::assertInstanceOf(CompiledFieldDefinition::class, $email);

        self::assertSame('users_lower_name_index', $name->indexedBy);
        self::assertNull($name->unindexedReason);
        self::assertSame('Bounded table', $createdAt->unindexedReason);
        self::assertNull($createdAt->indexedBy);
        self::assertNull($email->indexedBy);
        self::assertNull($email->unindexedReason);
    }

    /**
     * Test that an index override that is not a name is left off the compiled
     * field.
     *
     * @return void
     */
    public function testCompileIgnoresMalformedIndexOverrides(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'name' => ['sortable' => 'name', 'indexed' => 123, 'unindexed' => true],
        ]));

        $name = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $name);
        self::assertNull($name->indexedBy);
        self::assertNull($name->unindexedReason);
    }

    /**
     * Test that compile aggregates the declared searchable columns with the
     * match strategy each was declared with.
     *
     * @return void
     */
    public function testCompileAggregatesSearchableColumnsWithTheirStrategy(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'id'    => ['searchable' => 'id', 'strategy' => SearchStrategy::EXACT],
            'name'  => ['searchable' => 'name', 'strategy' => SearchStrategy::SUBSTRING],
            'email' => [],
        ]));

        self::assertSame(['id' => SearchStrategy::EXACT, 'name' => SearchStrategy::SUBSTRING], $schema->getSearchableColumns());

        $name = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $name);
        self::assertSame('name', $name->searchable);
        self::assertSame(SearchStrategy::SUBSTRING, $name->searchStrategy);
    }

    /**
     * Test that a searchable column without a strategy is left out of the
     * search surface but stays on the field for validation to report.
     *
     * @return void
     */
    public function testCompileDropsASearchableColumnDeclaredWithoutAStrategy(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'name' => ['searchable' => 'name'],
        ]));

        self::assertSame([], $schema->getSearchableColumns());

        $name = $schema->getField('name');

        self::assertInstanceOf(CompiledFieldDefinition::class, $name);
        self::assertSame('name', $name->searchable);
        self::assertNull($name->searchStrategy);
    }

    /**
     * Test that a search marker that is present but malformed is ignored: only
     * a string column paired with a real strategy contributes.
     *
     * @return void
     */
    public function testCompileIgnoresMalformedSearchMarkers(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'a' => ['searchable' => 123, 'strategy' => SearchStrategy::EXACT],
            'b' => ['searchable' => 'b', 'strategy' => 'substring'],
            'c' => ['strategy' => SearchStrategy::PREFIX],
        ]));

        self::assertSame([], $schema->getSearchableColumns());

        $b = $schema->getField('b');

        self::assertInstanceOf(CompiledFieldDefinition::class, $b);
        self::assertNull($b->searchStrategy);
    }

    /**
     * Test that a column declared searchable on two fields resolves to the last
     * strategy declared for it, so one column carries one match shape.
     *
     * @return void
     */
    public function testCompileResolvesARepeatedSearchableColumnToOneStrategy(): void
    {
        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'name'         => ['searchable' => 'name', 'strategy' => SearchStrategy::PREFIX],
            'display_name' => ['searchable' => 'name', 'strategy' => SearchStrategy::SUBSTRING],
        ]));

        self::assertSame(['name' => SearchStrategy::SUBSTRING], $schema->getSearchableColumns());
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
     * Test that a count entry compiles to an aggregate definition keyed by its
     * present key.
     *
     * @return void
     */
    public function testCompileCreatesAnAggregateDefinitionForACountMetric(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__aggregate__:posts' => [
                'key'      => 'posts',
                'metric'   => 'count',
                'relation' => 'posts',
                'default'  => true,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame([], $schema->getFieldKeys());

        $counts = $schema->getAggregateDefinitions();
        self::assertCount(1, $counts);
        self::assertArrayHasKey('posts', $counts);

        $count = $counts['posts'];
        self::assertInstanceOf(CompiledAggregateDefinition::class, $count);
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
        self::assertSame([], $schema->getAggregateDefinitions());
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
     * Test that caller guards are compiled onto field and aggregate definitions
     * apart from the row guards.
     *
     * @return void
     */
    public function testCompileCarriesCallerGuardsApartFromRowGuards(): void
    {
        $guard       = static fn (): bool => true;
        $callerGuard = static fn (): bool => false;

        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'secret'                 => ['guards' => [$guard], 'caller_guards' => ['visible' => $callerGuard]],
            '__aggregate__:comments' => ['metric' => 'count', 'relation' => 'comments', 'caller_guards' => [$callerGuard]],
        ]));

        self::assertSame([$guard], $schema->getField('secret')?->guards);
        self::assertSame([$callerGuard], $schema->getField('secret')->callerGuards);
        self::assertSame([], $schema->getAggregateDefinitions()['comments']->guards);
        self::assertSame([$callerGuard], $schema->getAggregateDefinitions()['comments']->callerGuards);
    }

    /**
     * Test that each filter, sort and relation key only caller-guarded fields
     * declare is recorded per kind with its declaring fields.
     *
     * @return void
     */
    public function testCompileRecordsConditionalKeysPerKindWithTheirDeclaringFields(): void
    {
        $visible = static fn (): bool => true;

        $schema = SchemaCompiler::compile($this->createStubResourceClass(Field::set(
            Field::scalar('salary')->visibleTo($visible)->filterable(Capability::RANGE),
            Field::scalar('salary', 'pay')->visibleTo($visible)->filterable(Capability::RANGE)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable(),
            Relation::to('posts', PostResource::class)->visibleTo($visible)->traversable(),
            Relation::to('tags', TagResource::class)->traversable(),
        )));

        self::assertSame(['salary' => ['salary', 'pay']], $schema->getConditionalFilterKeys());
        self::assertSame(['salary' => ['pay']], $schema->getConditionalSortKeys());
        self::assertSame(['posts' => ['posts']], $schema->getConditionalTraversalKeys());
    }

    /**
     * Test that a key any field declares without a caller guard is open to
     * every caller, while a declaration without a capability opens no filter.
     *
     * @return void
     */
    public function testCompileLeavesAKeyWithAnUnguardedDeclarerUnconditional(): void
    {
        $visible = static fn (): bool => true;

        $schema = SchemaCompiler::compile($this->createStubResourceClass([
            'email'   => ['filterable' => 'email', 'capability' => Capability::EXACT, 'sortable' => 'email', 'caller_guards' => [$visible]],
            'contact' => ['filterable' => 'email', 'capability' => Capability::EXACT, 'sortable' => 'email'],
            'salary'  => ['filterable' => 'salary', 'capability' => Capability::RANGE, 'caller_guards' => [$visible]],
            'pay'     => ['filterable' => 'salary', 'sortable' => 'salary', 'traversable' => 'posts'],
            'posts'   => ['relation' => 'posts', 'traversable' => 'posts', 'caller_guards' => [$visible]],
        ]));

        self::assertSame(['salary' => ['salary']], $schema->getConditionalFilterKeys());
        self::assertSame([], $schema->getConditionalSortKeys());
        self::assertSame([], $schema->getConditionalTraversalKeys());
    }

    /**
     * Test that a field with a row guard is refused on the query surface when
     * it compiles, whether or not schema validation runs.
     *
     * @return void
     */
    public function testCompileRefusesARowGuardedQueryableField(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('A field with a row guard may not be filterable');

        SchemaCompiler::compile($this->createStubResourceClass(Field::set(
            Field::scalar('salary')->guard(static fn (): bool => true)->filterable(Capability::RANGE),
        )));
    }

    /**
     * Test that one column filterable with two capabilities fails to compile,
     * whichever is declared first.
     *
     * @return void
     */
    public function testCompileRefusesOneColumnFilterableWithTwoCapabilities(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('Field "pay": Field is declared filterable against "salary" with the "exact" capability');

        SchemaCompiler::compile($this->createStubResourceClass(Field::set(
            Field::scalar('salary')->visibleTo(static fn (): bool => true)->filterable(Capability::RANGE),
            Field::scalar('salary', 'pay')->filterable(Capability::EXACT),
        )));
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
            '__aggregate__:articles' => [
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

        $counts = $schema->getAggregateDefinitions();
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
            '__aggregate__:articles' => [
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
            'name'                => [],
            'organization'        => ['constraint' => null],
            '__aggregate__:posts' => [
                'metric'   => 'count',
                'relation' => 'posts',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertInstanceOf(CompiledSchema::class, $schema);
    }

    /**
     * Test that a count with an __aggregate__: prefix is normalized to the
     * correct present key when no explicit key is provided.
     *
     * @return void
     */
    public function testCompileHandlesCountWithPrefixedKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__aggregate__:comments' => [
                'metric'   => 'count',
                'relation' => 'comments',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $counts = $schema->getAggregateDefinitions();
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
            '__aggregate__:posts' => [
                'metric'   => 'count',
                'relation' => 'posts',
            ],
            'name' => [],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertInstanceOf(CompiledFieldDefinition::class, $schema->getField('name'));
        self::assertArrayHasKey('posts', $schema->getAggregateDefinitions());
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
            '__aggregate__:posts' => [
                'key'      => 'published_posts',
                'metric'   => 'count',
                'relation' => 'posts',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $counts = $schema->getAggregateDefinitions();

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
            '__aggregate__:comments' => [
                'metric'   => 'count',
                'relation' => 'comments',
                'guards'   => [$guard],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $count  = $schema->getAggregateDefinitions()['comments'];

        self::assertFalse($count->isDefault);
        self::assertSame([$guard], $count->guards);
    }

    /**
     * Test that count transformers from the raw schema are carried onto the
     * compiled count definition.
     *
     * @return void
     */
    public function testCompilePreservesCountTransformers(): void
    {
        $transformer = fn ($resource, $value) => $value + 1;

        $resourceClass = $this->createStubResourceClass([
            '__aggregate__:comments' => [
                'metric'       => 'count',
                'relation'     => 'comments',
                'transformers' => [$transformer],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $count  = $schema->getAggregateDefinitions()['comments'];

        self::assertSame([$transformer], $count->transformers);
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
            '__aggregate__:likes' => [
                'metric'   => 'count',
                'relation' => 'likes',
                'default'  => ['truthy'],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertTrue($schema->getAggregateDefinitions()['likes']->isDefault);
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
     * Test that the extras, needs and fields of a field keep only their string
     * entries, reindexed as a list.
     *
     * @return void
     */
    public function testCompileKeepsOnlyTheStringEntriesOfAFieldsLists(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'avatar' => [
                'extras' => [1, 'media', null],
                'needs'  => ['first' => 'id', 'second' => 2],
                'fields' => [true, 'name'],
            ],
        ]);

        $field = SchemaCompiler::compile($resourceClass)->getField('avatar');

        self::assertInstanceOf(CompiledFieldDefinition::class, $field);
        self::assertSame(['media'], $field->extras);
        self::assertSame(['id'], $field->needs);
        self::assertSame(['name'], $field->fields);
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
     * Test that a declared relation limit is carried onto the field, and an
     * undeclared one stays null.
     *
     * @return void
     */
    public function testCompileCarriesTheRelationLimitOntoTheField(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'posts' => ['relation' => 'posts', 'limit' => 5],
            'tags'  => ['relation' => 'tags'],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame(5, $schema->getField('posts')?->limit);
        self::assertNull($schema->getField('tags')?->limit);
    }

    /**
     * Test that declared pivot columns are carried onto the field, and an
     * undeclared list is empty.
     *
     * @return void
     */
    public function testCompileCarriesThePivotColumnsOntoTheField(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'members' => ['relation' => 'members', 'pivot' => ['role', 'joined_at']],
            'tags'    => ['relation' => 'tags'],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame(['role', 'joined_at'], $schema->getField('members')?->pivot);
        self::assertSame([], $schema->getField('tags')?->pivot);
    }

    /**
     * Test that a fixture resource's plain fields compile with a null openApi
     * while timestamp() auto-declares a date-time format.
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
            '__aggregate__:posts_id' => [
                'key'      => 'posts_id',
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
                'default'  => true,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame([], $schema->getFieldKeys());
        $aggregates = $schema->getAggregateDefinitions();
        self::assertCount(1, $aggregates);
        self::assertArrayHasKey('posts_id', $aggregates);

        $agg = $aggregates['posts_id'];
        self::assertInstanceOf(CompiledAggregateDefinition::class, $agg);
        self::assertSame('posts_id', $agg->presentKey);
        self::assertSame('posts', $agg->relation);
        self::assertSame('id', $agg->column);
        self::assertSame(AggregateFunction::SUM, $agg->function);
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
            '__aggregate__:posts_id' => [
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

        $agg = $aggregates['posts_id'];
        self::assertInstanceOf(CompiledAggregateDefinition::class, $agg);
        self::assertSame(AggregateFunction::AVG, $agg->function);
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
            '__aggregate__:posts_id' => [
                'key'      => 'my_sum',
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('my_sum', $aggregates);
        self::assertSame('my_sum', $aggregates['my_sum']->presentKey);
        self::assertSame('posts', $aggregates['my_sum']->relation);
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
            '__aggregate__:posts_id' => [
                'metric'   => 'sum',
                'relation' => 'posts',
                'column'   => 'id',
                'guards'   => [$guard],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $agg    = $schema->getAggregateDefinitions()['posts_id'];

        self::assertFalse($agg->isDefault);
        self::assertSame([$guard], $agg->guards);
    }

    /**
     * Test that aggregate transformers from the raw schema are carried onto the
     * compiled aggregate definition.
     *
     * @return void
     */
    public function testCompilePreservesAggregateTransformers(): void
    {
        $transformer = fn ($resource, $value) => $value * 2;

        $resourceClass = $this->createStubResourceClass([
            '__aggregate__:posts_id' => [
                'metric'       => 'sum',
                'relation'     => 'posts',
                'column'       => 'id',
                'transformers' => [$transformer],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $agg    = $schema->getAggregateDefinitions()['posts_id'];

        self::assertSame([$transformer], $agg->transformers);
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
            '__aggregate__:posts_id' => [
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
            'name'                   => [],
            '__aggregate__:posts_id' => [
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
     * Test that keyed guard and transformer lists compile into lists, in their
     * declared order.
     *
     * @return void
     */
    public function testCompileReindexesKeyedGuardsAndTransformers(): void
    {
        $guard       = static fn (): bool => true;
        $transformer = static fn (mixed $value): mixed => $value;

        $resourceClass = $this->createStubResourceClass([
            'name' => [
                'accessor'     => 'name',
                'guards'       => ['visible' => $guard],
                'transformers' => [3 => $transformer, 'trimmed' => 'trim'],
            ],
        ]);

        $field = SchemaCompiler::compile($resourceClass)->getField('name');

        self::assertNotNull($field);
        self::assertSame([$guard], $field->guards);
        self::assertSame([$transformer, 'trim'], $field->transformers);
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
            '__aggregate__:posts_id' => [
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

        self::assertArrayHasKey('my_sum_key', $aggregates, 'non-prefixed schema key must become the presentKey as-is');
        self::assertSame('my_sum_key', $aggregates['my_sum_key']->presentKey);
    }

    /**
     * Test that the __aggregate__: prefix is stripped from an average's schema
     * key when no explicit key override is provided.
     *
     * @return void
     */
    public function testCompileStripsAvgPrefixWhenNoExplicitKeyProvided(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__aggregate__:comments_id' => [
                'metric'   => 'avg',
                'relation' => 'comments',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('comments_id', $aggregates, '__aggregate__: prefix must be stripped to derive the presentKey');
        self::assertSame('comments_id', $aggregates['comments_id']->presentKey);
    }

    /**
     * Test that the __aggregate__: prefix is stripped from a sum's schema key
     * when no explicit key override is provided.
     *
     * @return void
     */
    public function testCompileStripsSumPrefixWhenNoExplicitKeyProvided(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__aggregate__:comments_id' => [
                'metric'   => 'sum',
                'relation' => 'comments',
                'column'   => 'id',
            ],
        ]);

        $schema     = SchemaCompiler::compile($resourceClass);
        $aggregates = $schema->getAggregateDefinitions();

        self::assertArrayHasKey('comments_id', $aggregates, '__aggregate__: prefix must be stripped to derive the presentKey');
        self::assertSame('comments_id', $aggregates['comments_id']->presentKey);
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
            '__aggregate__:posts_id' => [
                'metric'     => 'sum',
                'relation'   => 'posts',
                'column'     => 'id',
                'constraint' => $constraint,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $agg    = $schema->getAggregateDefinitions()['posts_id'];

        self::assertSame($constraint, $agg->constraint);
    }

    /**
     * Test that every aggregate helper compiles to one definition keyed by its
     * present key, carrying its function, column and loaded attribute.
     *
     * @return void
     */
    public function testCompileKeysEveryAggregateFunctionByPresentKey(): void
    {
        $resourceClass = $this->createStubResourceClass(Field::set(
            Count::of('posts'),
            Exists::of('posts', 'has_posts'),
            Sum::of('orders', 'total'),
            Average::of('orders', 'total'),
            Min::of('variants', 'price'),
            Max::of('orderItems', 'created_at'),
        ));

        $aggregates = SchemaCompiler::compile($resourceClass)->getAggregateDefinitions();

        $shape = array_map(
            static fn (CompiledAggregateDefinition $definition): array => [$definition->function, $definition->relation, $definition->column, $definition->attribute()],
            $aggregates,
        );

        self::assertSame([
            'posts_count'                => [AggregateFunction::COUNT, 'posts', null, 'aggregate_posts_count'],
            'has_posts'                  => [AggregateFunction::EXISTS, 'posts', null, 'aggregate_has_posts'],
            'orders_sum_total'           => [AggregateFunction::SUM, 'orders', 'total', 'aggregate_orders_sum_total'],
            'orders_avg_total'           => [AggregateFunction::AVG, 'orders', 'total', 'aggregate_orders_avg_total'],
            'variants_min_price'         => [AggregateFunction::MIN, 'variants', 'price', 'aggregate_variants_min_price'],
            'order_items_max_created_at' => [AggregateFunction::MAX, 'orderItems', 'created_at', 'aggregate_order_items_max_created_at'],
        ], $shape);
    }

    /**
     * Test that an aggregate's declared OpenAPI contract reaches its compiled
     * definition, and that an undeclared one compiles without one.
     *
     * @return void
     */
    public function testCompileCarriesAnAggregatesDeclaredContract(): void
    {
        $resourceClass = $this->createStubResourceClass(Field::set(
            Count::of('posts')->openapi()->type('string')->end(),
            Sum::of('orders', 'total')->openapi()->type('string')->format('decimal')->end(),
            Average::of('orders', 'total')->openapi()->type('integer')->end(),
            Max::of('orders', 'total'),
        ));

        $contracts = array_map(
            static fn (CompiledAggregateDefinition $definition): ?array => $definition->openApi?->toArray(),
            SchemaCompiler::compile($resourceClass)->getAggregateDefinitions(),
        );

        self::assertSame([
            'posts_count'      => ['type' => 'string'],
            'orders_sum_total' => ['type' => 'string', 'format' => 'decimal'],
            'orders_avg_total' => ['type' => 'integer'],
            'orders_max_total' => null,
        ], $contracts);
    }

    /**
     * Test that a raw definition with an unrecognised metric and no aggregate
     * prefix compiles as a plain field.
     *
     * @return void
     */
    public function testCompileTreatsAnUnknownMetricOutsideTheAggregatePrefixAsAField(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'score' => ['metric' => 'median'],
            'level' => ['metric' => 42],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        self::assertSame(['score', 'level'], $schema->getFieldKeys());
        self::assertSame([], $schema->getAggregateDefinitions());
    }

    /**
     * Test that a count carries no column, a non-string column is dropped, and
     * a missing relation falls back to the present key.
     *
     * @return void
     */
    public function testCompileCarriesOnlyAStringColumn(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'posts' => ['metric' => 'count'],
            'stock' => ['metric' => 'max', 'relation' => 'variants', 'column' => 'stock'],
        ]);

        $aggregates = SchemaCompiler::compile($resourceClass)->getAggregateDefinitions();

        self::assertNull($aggregates['posts']->column);
        self::assertSame('posts', $aggregates['posts']->relation);
        self::assertSame('stock', $aggregates['stock']->column);
    }

    /**
     * Test that the present key resolves from an explicit key, then the
     * prefixed schema key, then the bare schema key.
     *
     * @return void
     */
    public function testResolveAggregateKeyPrefersTheExplicitKey(): void
    {
        self::assertSame('revenue', SchemaCompiler::resolveAggregateKey('__aggregate__:orders_sum_total', ['key' => 'revenue']));
        self::assertSame('orders_sum_total', SchemaCompiler::resolveAggregateKey('__aggregate__:orders_sum_total', ['key' => 7]));
        self::assertSame('orders', SchemaCompiler::resolveAggregateKey('orders', []));
        self::assertSame('x__aggregate__:orders', SchemaCompiler::resolveAggregateKey('x__aggregate__:orders', []));
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
