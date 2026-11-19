<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AliasedQueryableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\Fixtures\Resources\SharedColumnMarkerResource;
use Tests\Fixtures\Resources\SplitColumnMarkerResource;
use Tests\Fixtures\Resources\UndeclaredQueryMarkerResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the query surface reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurfaceReader::class)]
final class QuerySurfaceReaderTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that one surface is read per registered resource in registry order,
     * then one per resource a filter reaches through them.
     *
     * @return void
     */
    public function testReadsEachRegisteredResourceThenEachReachedOneOnce(): void
    {
        $surfaces = $this->reader()->read([
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ], $this->registry());

        self::assertSame(
            [UserResource::class, OrganizationResource::class, PostResource::class],
            array_map(static fn (QuerySurfaceDescriptor $surface): string => $surface->resource, $surfaces),
        );
    }

    /**
     * Test that each relation names the resource governing the keys under it,
     * per type for a polymorphic one and none where nothing does.
     *
     * @return void
     */
    public function testRelationsNameTheResourceGoverningThem(): void
    {
        $notes = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'governed_notes';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('noteable', 'id', 'noteable_key')->traversable(),
                    Relation::to('owner', PublicUserResource::class)->traversable(),
                    Relation::to('labels', \stdClass::class)->traversable(),
                    Relation::to('ghost', 'id', 'ghost_key')->traversable(),
                );
            }
        };

        $surfaces = $this->reader()->read([Note::class => $notes::class], $this->registry());

        self::assertEquals([
            new QueryRelationDescriptor('noteable', polymorphic: true),
            new QueryRelationDescriptor('owner', PublicUserResource::class),
            new QueryRelationDescriptor('labels'),
            new QueryRelationDescriptor('ghost'),
        ], $surfaces[0]->relations);
        self::assertSame([$notes::class, PublicUserResource::class], array_map(static fn (QuerySurfaceDescriptor $surface): string => $surface->resource, $surfaces));
    }

    /**
     * Test that resources reaching each other are each read once.
     *
     * @return void
     */
    public function testResourcesReachingEachOtherAreReadOnce(): void
    {
        $users = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'cyclic_surface_users';

            /** @var string */
            public static string $posts = '';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'), Relation::to('posts', self::$posts)->traversable());
            }
        };

        $posts = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'cyclic_surface_posts';

            /** @var string */
            public static string $user = '';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'), Relation::to('user', self::$user)->traversable());
            }
        };

        $users::$posts = $posts::class;
        $posts::$user  = $users::class;

        $surfaces = $this->reader()->read([User::class => $users::class], $this->registry());

        self::assertSame([$users::class, $posts::class], array_map(static fn (QuerySurfaceDescriptor $surface): string => $surface->resource, $surfaces));
    }

    /**
     * Test that an empty resource map reads no surfaces at all.
     *
     * @return void
     */
    public function testEmptyResourceMapReadsNoSurfaces(): void
    {
        self::assertSame([], $this->reader()->read([], $this->registry()));
    }

    /**
     * Test that a filterable column reports its compiled capability.
     *
     * @return void
     */
    public function testFilterableColumnReportsItsDeclaredCapability(): void
    {
        $columns = $this->columns(UserResource::class);

        self::assertSame(Capability::RANGE, $columns['id']->capability);
        self::assertSame(Capability::ENUM, $columns['status']->capability);
        self::assertSame(Capability::EXACT, $columns['organization_id']->capability);
    }

    /**
     * Test that a sortable-only column reports the order alone.
     *
     * @return void
     */
    public function testSortableOnlyColumnReportsTheOrderAlone(): void
    {
        $column = $this->columns(UserResource::class)['created_at'];

        self::assertTrue($column->sortable);
        self::assertNull($column->capability);
        self::assertNull($column->strategy);
        self::assertNull($column->unindexedReason);
    }

    /**
     * Test that a column never declared sortable reports no order.
     *
     * @return void
     */
    public function testFilterableColumnWithoutASortReportsNoOrder(): void
    {
        self::assertFalse($this->columns(UserResource::class)['organization_id']->sortable);
    }

    /**
     * Test that a column answering nothing is left out of the surface.
     *
     * @return void
     */
    public function testColumnAnsweringNothingIsLeftOutOfTheSurface(): void
    {
        $columns = $this->columns(UserResource::class);

        self::assertArrayNotHasKey('full_label', $columns);
        self::assertArrayNotHasKey('display_label', $columns);
    }

    /**
     * Test that an aliased column reports both property and column.
     *
     * @return void
     */
    public function testAliasedColumnReportsBothThePropertyAndTheColumn(): void
    {
        $column = $this->columns(AliasedQueryableUserResource::class)['email'];

        self::assertSame('email', $column->property);
        self::assertSame('email_address', $column->column);
    }

    /**
     * Test that an index-exempt order carries its recorded reason.
     *
     * @return void
     */
    public function testExemptedOrderCarriesTheRecordedReason(): void
    {
        $column = $this->columns(AliasedQueryableUserResource::class)['email'];

        self::assertTrue($column->sortable);
        self::assertSame('the table is bounded by the seat count', $column->unindexedReason);
    }

    /**
     * Test that a searchable column reports its compiled strategy.
     *
     * @return void
     */
    public function testSearchableColumnReportsItsMatchStrategy(): void
    {
        self::assertSame(
            SearchStrategy::SUBSTRING,
            $this->columns(AliasedQueryableUserResource::class)['email']->strategy,
        );
    }

    /**
     * Test that only traversable relations are reported.
     *
     * @return void
     */
    public function testOnlyTraversableRelationsAreReported(): void
    {
        self::assertEquals(
            [new QueryRelationDescriptor('organization', OrganizationResource::class)],
            $this->surface(AliasedQueryableUserResource::class)->relations,
        );
    }

    /**
     * Test that a resource declaring nothing queryable reads as empty.
     *
     * @return void
     */
    public function testResourceDeclaringNothingReadsAsAnEmptySurface(): void
    {
        $surface = $this->surface(OrganizationResource::class);

        self::assertSame([], $surface->columns);
        self::assertSame([], $surface->relations);
    }

    /**
     * Test that a declaration the compiled maps refuse is not reported.
     *
     * @return void
     */
    public function testDeclarationTheCompiledMapsRefuseIsNeverReported(): void
    {
        $columns = $this->columns(UndeclaredQueryMarkerResource::class);

        self::assertArrayNotHasKey('email', $columns);
        self::assertArrayNotHasKey('handle', $columns);
        self::assertArrayHasKey('id', $columns);
    }

    /**
     * Test that a filterable column reports its operators in order.
     *
     * @return void
     */
    public function testFilterableColumnReportsTheOperatorsItsCapabilityAnswers(): void
    {
        self::assertSame(
            ['$eq', '$in', '$neq', '$null', '$notNull'],
            $this->columns(UserResource::class)['status']->operators,
        );
    }

    /**
     * Test that a registered token is reported on the columns its capabilities
     * admit, and only those.
     *
     * @return void
     */
    public function testARegisteredTokenIsReportedOnlyOnTheColumnsItsCapabilitiesAdmit(): void
    {
        $this->registry()->register('$starts', static function (): void {}, Capability::ENUM);

        $columns = $this->columns(UserResource::class);

        self::assertSame(['$eq', '$in', '$neq', '$null', '$notNull', '$starts'], $columns['status']->operators);
        self::assertSame(['$eq', '$in', '$null', '$notNull'], $columns['email']->operators);
    }

    /**
     * Test that an operator removed from the registry is dropped.
     *
     * @return void
     */
    public function testARemovedOperatorIsDroppedFromTheColumn(): void
    {
        $this->registry()->remove('$in');

        $column = $this->columns(UserResource::class)['status'];

        self::assertSame(['$eq', '$neq', '$null', '$notNull'], $column->operators);
        self::assertSame(Capability::ENUM, $column->capability);
    }

    /**
     * Test that a column whose capability admits no operator reports no
     * capability but keeps its order.
     *
     * @return void
     */
    public function testAColumnLeftWithNoAdmittedOperatorReportsNoCapability(): void
    {
        foreach (['$eq', '$in', '$null', '$notNull'] as $token) {
            $this->registry()->remove($token);
        }

        $columns = $this->columns(AliasedQueryableUserResource::class);

        self::assertSame([], $columns['email']->operators);
        self::assertNull($columns['email']->capability);
        self::assertTrue($columns['email']->sortable);
    }

    /**
     * Test that each declared column reports only what it answers.
     *
     * @return void
     */
    public function testAFieldNamingSeveralColumnsIsReportedOncePerColumn(): void
    {
        $columns = $this->surface(SplitColumnMarkerResource::class)->columns;

        self::assertCount(2, $columns);

        self::assertSame('label', $columns[0]->property);
        self::assertSame('label_filter', $columns[0]->column);
        self::assertSame(Capability::EXACT, $columns[0]->capability);
        self::assertFalse($columns[0]->sortable);

        self::assertSame('label', $columns[1]->property);
        self::assertSame('label_sort', $columns[1]->column);
        self::assertNull($columns[1]->capability);
        self::assertTrue($columns[1]->sortable);
    }

    /**
     * Test that an exemption reason stays with the sortable column.
     *
     * @return void
     */
    public function testTheExemptionReasonTravelsWithTheOrderedColumnAlone(): void
    {
        $columns = $this->surface(SplitColumnMarkerResource::class)->columns;

        self::assertNull($columns[0]->unindexedReason);
        self::assertSame('the label table is a fixed lookup', $columns[1]->unindexedReason);
    }

    /**
     * Test that an order belongs to the field that declared it.
     *
     * @return void
     */
    public function testAColumnIsOrderableOnlyOnTheFieldThatDeclaredTheOrder(): void
    {
        $columns = $this->surface(SharedColumnMarkerResource::class)->columns;

        $orderable = array_values(array_filter(
            $columns,
            static fn (QueryColumnDescriptor $column): bool => $column->sortable,
        ));

        self::assertSame(['owner', 'note'], array_column($orderable, 'property'));
        self::assertSame(['shared', 'shared'], array_column($orderable, 'column'));

        $filterable = array_values(array_filter(
            $columns,
            static fn (QueryColumnDescriptor $column): bool => $column->capability !== null,
        ));

        self::assertSame(['alias'], array_column($filterable, 'property'));
        self::assertFalse($filterable[0]->sortable);
    }

    /**
     * Test that a refused marker does not abandon the field's later columns.
     *
     * @return void
     */
    public function testARefusedMarkerDoesNotAbandonTheRestOfTheField(): void
    {
        $note = array_values(array_filter(
            $this->surface(SharedColumnMarkerResource::class)->columns,
            static fn (QueryColumnDescriptor $column): bool => $column->property === 'note',
        ));

        self::assertCount(1, $note);
        self::assertSame('shared', $note[0]->column);
        self::assertTrue($note[0]->sortable);
    }

    /**
     * Test that a null field definition does not abandon later keys.
     *
     * @return void
     */
    public function testFieldKeyWithoutADefinitionIsSteppedOver(): void
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
            filterable: 'name',
            filterCapability: Capability::EXACT,
        );

        $schema = new CompiledSchema(['ghost' => null, 'name' => $field], [], ['name' => Capability::EXACT]); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $columns = $this->ghostSurface('GhostResource')->columns;

        self::assertCount(1, $columns);
        self::assertSame('name', $columns[0]->property);
        self::assertSame('name', $columns[0]->column);
        self::assertSame(Capability::EXACT, $columns[0]->capability);
    }

    /**
     * Test that a column only caller-guarded fields declare reports its filter
     * and its order as conditional independently, and only where it answers
     * them.
     *
     * @return void
     */
    public function testConditionalFilterAndOrderAreReportedIndependently(): void
    {
        $field = static fn (string $column): CompiledFieldDefinition => new CompiledFieldDefinition(
            accessor        : null,
            compute         : null,
            relation        : null,
            resource        : null,
            fields          : null,
            constraint      : null,
            extras          : [],
            needs           : [],
            guards          : [],
            transformers    : [],
            filterable      : $column,
            filterCapability: Capability::EXACT,
            sortable        : $column,
        );

        $schema = new CompiledSchema(
            fields               : ['email' => $field('email'), 'name' => $field('name'), 'payload' => $field('payload'), 'secret' => $field('secret')],
            filterableColumns    : ['email' => Capability::EXACT, 'name' => Capability::EXACT, 'payload' => Capability::DOCUMENT, 'secret' => Capability::EXACT],
            sortableColumns      : ['email', 'name', 'payload'],
            conditionalFilterKeys: ['email' => ['email'], 'payload' => ['payload']],
            conditionalSortKeys  : ['name' => ['name'], 'payload' => ['payload'], 'secret' => ['secret']],
        );

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);
        $this->registry()->remove('$contains');

        $columns = [];

        foreach ($this->ghostSurface('GhostResource')->columns as $column) {
            $columns[$column->property] = [$column->filterConditional, $column->sortConditional];
        }

        self::assertSame(['email' => [true, false], 'name' => [false, true], 'payload' => [false, true], 'secret' => [false, false]], $columns);
    }

    /**
     * Read a single resource's surface.
     *
     * @param  class-string  $resourceClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(string $resourceClass): QuerySurfaceDescriptor
    {
        return $this->reader()->read([User::class => $resourceClass], $this->registry())[0];
    }

    /**
     * Build the reader under test.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader
     */
    private function reader(): QuerySurfaceReader
    {
        return new QuerySurfaceReader(app(SchemaIntrospectionProvider::class));
    }

    /**
     * Read the surface of a resource name the compiler has been primed for.
     *
     * @param  string  $resourceClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function ghostSurface(string $resourceClass): QuerySurfaceDescriptor
    {
        /** @var class-string $resourceClass */
        return $this->surface($resourceClass);
    }

    /**
     * Return the bound operator registry, seeded with the shipped operators.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry
     */
    private function registry(): OperatorRegistry
    {
        return app(OperatorRegistry::class);
    }

    /**
     * Read a single resource's columns, keyed by property.
     *
     * @param  class-string  $resourceClass
     * @return array<string, \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     */
    private function columns(string $resourceClass): array
    {
        $columns = [];

        foreach ($this->surface($resourceClass)->columns as $column) {
            $columns[$column->property] = $column;
        }

        return $columns;
    }
}
