<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\TenantUser;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AliasedQueryableUserResource;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\CappedPageUserResource;
use Tests\Fixtures\Resources\GatheringResource;
use Tests\Fixtures\Resources\GuardedQueryablePostResource;
use Tests\Fixtures\Resources\GuardedQueryableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\Fixtures\Resources\SharedColumnMarkerResource;
use Tests\Fixtures\Resources\SortableAuthoredPostResource;
use Tests\Fixtures\Resources\SortableUserResource;
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
     * Test that only a resource whose page size bounds differ from the
     * configured ones carries them.
     *
     * @return void
     */
    public function testOnlyAResourceWithItsOwnPageSizeCarriesIt(): void
    {
        self::assertEquals(new PageSize(2, 2), $this->surface(CappedPageUserResource::class)->pageSize);
        self::assertNull($this->surface(UserResource::class)->pageSize);
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
     * Test that each surface lists every model it is bound to or reached
     * through, once.
     *
     * @return void
     */
    public function testEachSurfaceListsEveryModelItFilters(): void
    {
        $surfaces = $this->reader()->read([
            User::class         => UserResource::class,
            TenantUser::class   => UserResource::class,
            Organization::class => OrganizationResource::class,
        ], $this->registry());

        $models = [];

        foreach ($surfaces as $surface) {
            $models[$surface->resource] = $surface->models;
        }

        self::assertSame(
            [UserResource::class => [User::class, TenantUser::class], OrganizationResource::class => [Organization::class], PostResource::class => [Post::class]],
            $models,
        );
    }

    /**
     * Test that one resource is read over the given model alone, as the full
     * read reads it.
     *
     * @return void
     */
    public function testReadOneReadsTheResourceOverTheGivenModelAlone(): void
    {
        $map     = [User::class => UserResource::class, Organization::class => OrganizationResource::class];
        $surface = $this->reader()->readOne(UserResource::class, User::class, $map, $this->registry());

        self::assertEquals($this->reader()->read($map, $this->registry())[0], $surface);
        self::assertSame([User::class], $surface->models);
    }

    /**
     * Test that a relation only some callers may traverse is reported as such.
     *
     * @return void
     */
    public function testARelationOnlySomeCallersMayTraverseIsReportedAsSuch(): void
    {
        self::assertEquals(
            [new QueryRelationDescriptor('posts', GuardedQueryablePostResource::class, conditional: true)],
            $this->surface(GuardedQueryableUserResource::class)->relations,
        );
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
     * Test that each scope is read with its description, its arguments and
     * whether its rules require each, and whether it is conditional.
     *
     * @return void
     */
    public function testScopesAreReadWithTheirArgumentsAndConditionality(): void
    {
        $surface = $this->reader()->read([Gathering::class => GatheringResource::class], $this->registry())[0];

        self::assertEquals([
            new QueryScopeDescriptor('upcoming', 'Gatherings that have not started yet.'),
            new QueryScopeDescriptor('near', 'Gatherings within km kilometres of the point.', ['lat' => true, 'lng' => true, 'km' => true]),
            new QueryScopeDescriptor('mine', conditional: true),
        ], $surface->scopes);
    }

    /**
     * Test that an argument is required only where its own rules demand it.
     *
     * @return void
     */
    public function testAnArgumentIsRequiredOnlyWhereItsOwnRulesDemandIt(): void
    {
        $rules = ['km' => 'sometimes|required|integer', 'ids.*' => 'required|integer', 'tag' => ['required']];
        $scope = new CompiledScopeDefinition('within', 'within', $rules, ['km', 'ids', 'tag']);

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => new CompiledSchema([], scopes: ['within' => $scope])]);

        self::assertSame(['km' => false, 'ids' => false, 'tag' => true], $this->ghostSurface('GhostResource')->scopes[0]->arguments);
    }

    /**
     * Test that an argument the scope's method takes with no default is
     * required whatever its rules say, and a variadic one is not.
     *
     * @return void
     */
    public function testAnArgumentTheMethodTakesWithoutADefaultIsRequired(): void
    {
        $scopes = [
            'named'      => new CompiledScopeDefinition('named', 'named', ['first' => 'sometimes|string', 'second' => 'string'], ['first', 'second']),
            'namedAmong' => new CompiledScopeDefinition('namedAmong', 'namedAmong', ['name' => 'nullable|string', 'ids' => 'array'], ['name', 'ids']),
        ];

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => new CompiledSchema([], scopes: $scopes)]);

        $surface = $this->ghostSurface('GhostResource', Gathering::class);

        self::assertSame(['first' => true, 'second' => true], $surface->scopes[0]->arguments);
        self::assertSame(['name' => true, 'ids' => false], $surface->scopes[1]->arguments);
    }

    /**
     * Test that each sortable aggregate and each sortable column of the
     * resource a sortable relation renders is read as an unindexed order key,
     * and the rendered resource is read in turn.
     *
     * @return void
     */
    public function testSortableAggregatesAndRelationsAreReadAsUnindexedOrderKeys(): void
    {
        $surfaces = $this->reader()->read([User::class => SortableUserResource::class], $this->registry());
        $orders   = [];

        foreach ($surfaces[0]->columns as $column) {
            $orders[$column->property] = [$column->column, $column->sortable, $column->unindexedReason];
        }

        self::assertSame(
            [SortableUserResource::class, ArticleResource::class, SortableAuthoredPostResource::class],
            array_map(static fn (QuerySurfaceDescriptor $surface): string => $surface->resource, $surfaces),
        );
        self::assertSame([
            'name'                       => ['name', true, null],
            'aggregates.posts_count'     => ['aggregates.posts_count', true, 'Leaderboard over a few hundred authors'],
            'aggregates.published_posts' => ['aggregates.published_posts', true, 'Leaderboard over a few hundred authors'],
            'aggregates.article_views'   => ['aggregates.article_views', true, 'Admin list of a few hundred authors'],
            'latestArticle.views'        => ['latestArticle.views', true, 'Admin list of a few hundred authors'],
            'latestPost.published'       => ['latestPost.published', true, 'Admin list of a few hundred authors'],
        ], $orders);
        self::assertSame([], $surfaces[0]->relations);
    }

    /**
     * Test that an aggregate or relation order key is conditional where its
     * aggregate, its relation or the related column is caller-guarded, and a
     * relation rendering no API resource offers no key.
     *
     * @return void
     */
    public function testAggregateAndRelationOrderKeysCarryEveryGuardOnTheirPath(): void
    {
        $guard     = static fn (): bool => true;
        $aggregate = static fn (string $key, array $guards): CompiledAggregateDefinition => new CompiledAggregateDefinition(
            presentKey     : $key,
            relation       : 'posts',
            function       : AggregateFunction::COUNT,
            column         : null,
            constraint     : null,
            isDefault      : false,
            guards         : [],
            callerGuards   : $guards,
            unindexedReason: 'Leaderboard',
        );
        $relation = static fn (string $relation, array $guards): CompiledFieldDefinition => new CompiledFieldDefinition(
            accessor         : null,
            compute          : null,
            relation         : $relation,
            resource         : GuardedQueryableUserResource::class,
            fields           : null,
            constraint       : null,
            extras           : [],
            needs            : [],
            guards           : [],
            transformers     : [],
            callerGuards     : $guards,
            sortThroughReason: 'Admin list',
        );

        $schema = new CompiledSchema(
            fields              : ['author' => $relation('author', []), 'checker' => $relation('reviewer', [$guard])],
            aggregates          : ['posts_count' => $aggregate('posts_count', []), 'spend' => $aggregate('spend', [$guard])],
            traversableResources: ['author' => GuardedQueryableUserResource::class, 'reviewer' => GuardedQueryableUserResource::class, 'plain' => \stdClass::class],
            sortableRelations   : ['unrendered' => ['author'], 'plain' => ['author'], 'author' => ['author'], 'reviewer' => ['checker']],
        );

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $conditional = [];

        foreach ($this->ghostSurface('GhostResource')->columns as $column) {
            $conditional[$column->property . ' ' . $column->column] = $column->sortConditional;
        }

        self::assertSame([
            'aggregates.posts_count aggregates.posts_count' => false,
            'aggregates.spend aggregates.spend'             => true,
            'author.id author.id'                           => false,
            'author.name author.name'                       => false,
            'author.email author.email'                     => true,
            'checker.id reviewer.id'                        => true,
            'checker.name reviewer.name'                    => true,
            'checker.email reviewer.email'                  => true,
        ], $conditional);
    }

    /**
     * Test that a relation order key names each column the rendered resource
     * sorts by once, under the first property declaring it, and no column the
     * compiled sort map leaves out.
     *
     * @return void
     */
    public function testRelationOrderKeysNameEachSortableColumnOnceUnderItsFirstProperty(): void
    {
        $child  = static fn (string $column): CompiledFieldDefinition => new CompiledFieldDefinition(null, null, null, null, null, null, [], [], [], [], sortable: $column);
        $writer = new CompiledFieldDefinition(null, null, 'author', PublicUserResource::class, null, null, [], [], [], [], sortThroughReason: 'Admin list');

        $this->setStaticProperty(SchemaCompiler::class, 'cache', [
            'GhostResource' => new CompiledSchema(
                fields              : ['writer' => $writer],
                traversableResources: ['author' => PublicUserResource::class],
                sortableRelations   : ['author' => ['writer']],
            ),
            PublicUserResource::class => new CompiledSchema(
                fields         : ['name' => $child('name'), 'label' => $child('name'), 'secret' => $child('secret')],
                sortableColumns: ['name'],
            ),
        ]);

        $columns = array_map(static fn (QueryColumnDescriptor $column): array => [$column->property, $column->column], $this->ghostSurface('GhostResource')->columns);

        self::assertSame([['writer.name', 'author.name']], $columns);
    }

    /**
     * Read a single resource's surface over the given model.
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $modelClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(string $resourceClass, string $modelClass = User::class): QuerySurfaceDescriptor
    {
        return $this->reader()->read([$modelClass => $resourceClass], $this->registry())[0];
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
     * Read the surface of a resource name the compiler has been primed for,
     * over the given model.
     *
     * @param  string  $resourceClass
     * @param  class-string  $modelClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function ghostSurface(string $resourceClass, string $modelClass = User::class): QuerySurfaceDescriptor
    {
        /** @var class-string $resourceClass */
        return $this->surface($resourceClass, $modelClass);
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
