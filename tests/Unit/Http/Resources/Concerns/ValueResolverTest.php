<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\AggregateLine;
use Tests\Fixtures\Models\AggregateOwner;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PlainJsonResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValueResolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValueResolver::class)]
final class ValueResolverTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver */
    private ValueResolver $resolver;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        ValueResolver::clearCache();

        $this->resolver = new ValueResolver(new GuardEvaluator);
    }

    /**
     * Clear the static memo caches after each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        ValueResolver::clearCache();

        parent::tearDown();
    }

    /**
     * Test that the cast-accessor reflection result is memoised per class and
     * field after the first resolution.
     *
     * @return void
     */
    public function testCastAccessorReflectionIsMemoisedPerClassAndField(): void
    {

        $model = new class {
            /**
             * A cast accessor returning an Attribute instance.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             */
            public function nickname(): Attribute
            {
                return Attribute::make(get: fn () => 'Nick');
            }

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }

            /**
             * Magic getter resolving the cast accessor value.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return 'Nick';
            }
        };

        $result = $this->resolver->resolveFieldValue('nickname', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertSame('Nick', $result);

        $cache = $this->getStaticProperty(ValueResolver::class, 'castAccessorCache');

        self::assertTrue($cache[$model::class]['nickname']);
    }

    /**
     * Test that a seeded cast-accessor memo short-circuits the reflection, so
     * the cached decision is honoured rather than recomputed.
     *
     * @return void
     */
    public function testCastAccessorMemoShortCircuitsReflection(): void
    {

        $model = new class {
            /**
             * A plain method whose return type is not an Attribute.
             *
             * @return string
             */
            public function label(): string
            {
                return 'real';
            }

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }

            /**
             * Magic getter used when the field resolves via the memoised path.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return 'from-memo';
            }
        };

        // label() returns a string, so only the seeded memo yields __get
        $this->setStaticProperty(ValueResolver::class, 'castAccessorCache', [$model::class => ['label' => true]]);

        $result = $this->resolver->resolveFieldValue('label', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertSame('from-memo', $result);
    }

    /**
     * Test that a method returning a type other than Attribute is not a cast
     * accessor and the memo records the rejection.
     *
     * @return void
     */
    public function testComputeIsCastAccessorRejectsNonAttributeReturnType(): void
    {

        $model = new class {
            /**
             * A method whose return type is a named type other than Attribute.
             *
             * @return string
             */
            public function label(): string
            {
                return 'real';
            }

            /**
             * Return the attributes array without the field.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }
        };

        $result = $this->resolver->resolveFieldValue('label', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertInstanceOf(MissingValue::class, $result);

        $cache = $this->getStaticProperty(ValueResolver::class, 'castAccessorCache');

        self::assertFalse($cache[$model::class]['label']);
    }

    /**
     * Test that the child field set drops empty entries and is re-indexed.
     *
     * @return void
     */
    public function testRelationFieldsDropEmptyEntries(): void
    {
        // A leading empty entry makes the array_values re-index observable
        $definition = $this->makeFieldDefinition(fields: ['', 'id', 'name']);

        self::assertSame(['id', 'name'], $this->invokeMethod($this->resolver, 'getRelationFields', $definition));
    }

    /**
     * Test that a relation without explicit child fields yields none.
     *
     * @return void
     */
    public function testRelationFieldsAreNullWithoutExplicitChildFields(): void
    {
        $definition = $this->makeFieldDefinition(fields: null);

        self::assertNull($this->invokeMethod($this->resolver, 'getRelationFields', $definition));
    }

    /**
     * Test that a relation's explicit child fields render without the empty
     * entries among them.
     *
     * @return void
     */
    public function testResolveFieldValueRendersExplicitChildFieldsAroundAnEmptyEntry(): void
    {
        $rendered = $this->renderOrganizationWithChildFields(['name', '', 'slug']);

        self::assertSame('ChildFieldsOrg', $rendered['name']);
        self::assertSame('child-fields-org', $rendered['slug']);
        self::assertArrayNotHasKey('created_at', $rendered);
    }

    /**
     * Test that a relation whose only child field is empty renders the fixed
     * fields alone.
     *
     * @return void
     */
    public function testResolveFieldValueRendersAnEmptyOnlyChildFieldSetAsTheFixedFields(): void
    {
        $rendered = $this->renderOrganizationWithChildFields(['']);

        self::assertSame(['_type', 'id'], array_keys($rendered));
    }

    /**
     * Test that clearing the cache empties the cast-accessor, related-model and
     * pivot-accessor memos.
     *
     * @return void
     */
    public function testClearCacheEmptiesTheCastAccessorRelatedModelAndPivotAccessorMemos(): void
    {
        $this->setStaticProperty(ValueResolver::class, 'castAccessorCache', ['Foo' => ['bar' => true]]);
        $this->setStaticProperty(ValueResolver::class, 'relatedModelCache', ['Foo' => ['bar' => null]]);
        $this->setStaticProperty(ValueResolver::class, 'pivotAccessorCache', ['Foo' => ['bar' => 'pivot']]);

        ValueResolver::clearCache();

        self::assertSame([], $this->getStaticProperty(ValueResolver::class, 'castAccessorCache'));
        self::assertSame([], $this->getStaticProperty(ValueResolver::class, 'relatedModelCache'));
        self::assertSame([], $this->getStaticProperty(ValueResolver::class, 'pivotAccessorCache'));
    }

    /**
     * Test that a many-to-many relation declaring pivot columns hands each
     * child the columns and the accessor its relation loads the pivot under,
     * memoised per owner class and relation.
     *
     * @return void
     */
    public function testManyToManyChildrenRenderThePivotUnderTheRelationsAccessor(): void
    {
        $tags  = $this->resolveTags($this->postWithTags('tagging', ['role' => 'primary']), 'typedTags');
        $plain = $this->resolveTags($this->postWithTags('pivot', ['role' => 'secondary']), 'tags');

        self::assertSame(['role' => 'primary'], $tags[0]['pivot']);
        self::assertSame(['role' => 'secondary'], $plain[0]['pivot']);
        self::assertSame(['typedTags' => 'tagging', 'tags' => 'pivot'], $this->getStaticProperty(ValueResolver::class, 'pivotAccessorCache')[Post::class]);
    }

    /**
     * Test that a memoised pivot accessor is honoured, so the owner's relation
     * is not resolved again.
     *
     * @return void
     */
    public function testAMemoisedPivotAccessorIsHonoured(): void
    {
        $this->setStaticProperty(ValueResolver::class, 'pivotAccessorCache', [Post::class => ['tags' => 'membership']]);

        $tags = $this->resolveTags($this->postWithTags('membership', ['role' => 'primary']), 'tags');

        self::assertSame(['role' => 'primary'], $tags[0]['pivot']);
    }

    /**
     * Test that pivot columns on a loaded relation that is not many-to-many, or
     * that the model does not declare, reach no child, and each miss is
     * memoised.
     *
     * @return void
     */
    public function testPivotColumnsOnARelationThatIsNotManyToManyReachNoChild(): void
    {
        $user  = new User;
        $posts = new Collection([(new Post)->forceFill(['id' => 1, 'title' => 'First'])->setRelation('pivot', (new Post)->forceFill(['role' => 'primary']))]);

        $user->setRelation('posts', $posts)->setRelation('drafts', $posts);

        foreach (['posts', 'drafts'] as $relation) {

            $definition = $this->makeFieldDefinition(relation: $relation, resource: PostResource::class, fields: ['id'], pivot: ['role']);
            $result     = $this->resolver->resolveFieldValue($relation, $definition, new JsonResource($user), null);

            self::assertInstanceOf(ApiResourceCollection::class, $result);
            self::assertArrayNotHasKey('pivot', $result->resolve()[0]);
        }

        self::assertSame(['posts' => null, 'drafts' => null], $this->getStaticProperty(ValueResolver::class, 'pivotAccessorCache')[User::class]);
    }

    /**
     * Test that a many-to-many relation registered at runtime hands each child
     * the accessor its pivot loads under.
     *
     * @return void
     */
    public function testADynamicManyToManyRelationRendersThePivotUnderItsAccessor(): void
    {
        $property = new \ReflectionProperty(Model::class, 'relationResolvers');
        $original = $property->getValue();

        try {
            Post::resolveRelationUsing('labels', static fn (Post $post): BelongsToMany => $post->belongsToMany(Tag::class, 'post_tag')->as('labelling'));

            $post = $this->postWithTags('labelling', ['role' => 'primary']);

            $post->setRelation('labels', $post->getRelation('tags'));

            self::assertSame(['role' => 'primary'], $this->resolveTags($post, 'labels')[0]['pivot']);
        } finally {
            $property->setValue(null, $original);
        }
    }

    /**
     * Test that a child resource unable to render a pivot is wrapped as it is.
     *
     * @return void
     */
    public function testAChildResourceWithoutPivotSupportIsWrappedAsItIs(): void
    {
        $definition = $this->makeFieldDefinition(relation: 'tags', resource: PlainJsonResource::class, pivot: ['role']);
        $result     = $this->resolver->resolveFieldValue('tags', $definition, new JsonResource($this->postWithTags('pivot', ['role' => 'primary'])), null);

        self::assertInstanceOf(AnonymousResourceCollection::class, $result);
        self::assertInstanceOf(Collection::class, $result->collection);
        self::assertContainsOnlyInstancesOf(PlainJsonResource::class, $result->collection);
    }

    /**
     * Test that the pivot payload reads each present column through the pivot's
     * casts, a null included, omits an absent one and reduces a pure enum to
     * its name.
     *
     * @return void
     */
    public function testPivotPayloadReadsEachPresentColumnThroughThePivotsCasts(): void
    {
        $pivot = new class extends Pivot {
            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            #[\Override]
            protected function casts(): array
            {
                return ['platform' => Platform::class, 'status' => UserStatus::class];
            }
        };

        $tag = (new Tag)->setRelation('membership', $pivot->setRawAttributes(['tag_id' => 1, 'platform' => 'MOBILE_APP', 'status' => 'active', 'note' => null]));

        self::assertSame(
            ['platform' => 'mobile_app', 'status' => UserStatus::ACTIVE, 'note' => null],
            $this->resolver->resolvePivotPayload(new JsonResource($tag), 'membership', ['platform', 'status', 'note', 'joined_at']),
        );
    }

    /**
     * Test that a model without the pivot loaded, or a value that is not a
     * model, has no pivot payload.
     *
     * @return void
     */
    public function testPivotPayloadIsEmptyWithoutALoadedPivot(): void
    {
        $tag = (new Tag)->setRelation('pivot', null);

        self::assertSame([], $this->resolver->resolvePivotPayload(new Tag, 'pivot', ['role']));
        self::assertSame([], $this->resolver->resolvePivotPayload($tag, 'pivot', ['role']));
        self::assertSame([], $this->resolver->resolvePivotPayload(['pivot' => ['role' => 'primary']], 'pivot', ['role']));
    }

    /**
     * Test that a simple property is resolved from the underlying model.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsSimplePropertyValue(): void
    {

        $model = new class {
            /** @var string */
            public string $name = 'Alice';
        };

        $resource   = new JsonResource($model);
        $definition = $this->makeFieldDefinition();

        $result = $this->resolver->resolveFieldValue('name', $definition, $resource, null);

        self::assertSame('Alice', $result);
    }

    /**
     * Test that a computed value is resolved from a callable.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsComputedValueFromCallable(): void
    {

        $resource   = new JsonResource(new \stdClass);
        $definition = $this->makeFieldDefinition(
            compute: fn ($resource, $request) => 'computed-value',
        );

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertSame('computed-value', $result);
    }

    /**
     * Test that a computed value is resolved from a method name on the
     * resource.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsComputedValueFromMethodName(): void
    {

        $resource = new class (new \stdClass) extends JsonResource {
            /**
             * Compute a custom value.
             *
             * @param  mixed  $request
             * @return string
             */
            public function customCompute(mixed $request): string
            {
                return 'method-computed';
            }
        };

        $definition = $this->makeFieldDefinition(compute: 'customCompute');

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertSame('method-computed', $result);
    }

    /**
     * Test that an accessor string path resolves a value from the model using
     * data_get.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsAccessorValueFromStringPath(): void
    {

        $model         = new \stdClass;
        $model->nested = (object) ['key' => 'nested-value'];

        $resource   = new JsonResource($model);
        $definition = $this->makeFieldDefinition(accessor: 'nested.key');

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertSame('nested-value', $result);
    }

    /**
     * Test that a MissingValue is returned when a guard fails.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueWhenGuardFails(): void
    {

        $resource   = new JsonResource(new \stdClass);
        $definition = $this->makeFieldDefinition(
            guards: [fn ($resource, $request) => false],
        );

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Provide each combination of caller and row guard results.
     *
     * @return iterable<string, array{bool, bool}>
     */
    public static function guardOutcomes(): iterable
    {
        yield 'both pass' => [true, true];
        yield 'caller denies' => [false, true];
        yield 'row denies' => [true, false];
        yield 'both deny' => [false, false];
    }

    /**
     * Test that a field shows only when its caller guards and then its row
     * guards pass, each handed the request.
     *
     * @param  bool  $caller
     * @param  bool  $row
     * @return void
     */
    #[DataProvider('guardOutcomes')]
    public function testResolveFieldValueShowsOnlyWhenCallerAndRowGuardsPass(bool $caller, bool $row): void
    {
        $request    = Request::create('/');
        $definition = $this->makeFieldDefinition(
            guards      : [static fn ($resource, $given): bool => $given === $request && $row],
            callerGuards: [static fn ($given): bool => $given === $request && $caller],
        );

        $result = $this->resolver->resolveFieldValue('name', $definition, new JsonResource((object) ['name' => 'Alice']), $request);

        self::assertSame($caller && $row, !$result instanceof MissingValue);
    }

    /**
     * Test that an aggregate shows only when its caller guards and then its row
     * guards pass, each handed the request.
     *
     * @param  bool  $caller
     * @param  bool  $row
     * @return void
     */
    #[DataProvider('guardOutcomes')]
    public function testResolveAggregatesPayloadShowsOnlyWhenCallerAndRowGuardsPass(bool $caller, bool $row): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $request = Request::create('/');
        $owner   = (new User)->setRawAttributes(['aggregate_posts_count' => 2]);
        $schema  = $this->aggregateSchema($this->aggregate(
            'posts_count',
            AggregateFunction::COUNT,
            guards      : [static fn ($resource, $given): bool => $given === $request && $row],
            callerGuards: [static fn ($given): bool => $given === $request && $caller],
        ));

        self::assertSame($caller && $row ? ['posts_count' => 2] : [], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', $request));
    }

    /**
     * Test that a caller its guards deny never reaches a row guard, accessor,
     * compute or transformer of a field or an aggregate.
     *
     * @return void
     */
    public function testADeniedCallerRunsNothingElse(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject;

        $rowGuard = static function () use ($calls): bool {

            $calls[] = 'row guard';

            return true;
        };

        $transformer = static function ($resource, $value) use ($calls): mixed {

            $calls[] = 'transformer';

            return $value;
        };

        $resolve = static function () use ($calls): string {

            $calls[] = 'resolve';

            return 'x';
        };

        $deny     = [static fn (): bool => false];
        $computed = $this->makeFieldDefinition(compute: $resolve, guards: [$rowGuard], transformers: [$transformer], callerGuards: $deny);
        $accessed = new CompiledFieldDefinition(
            accessor    : $resolve,
            compute     : null,
            relation    : null,
            resource    : null,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : [],
            guards      : [$rowGuard],
            transformers: [$transformer],
            callerGuards: $deny,
        );
        $schema = $this->aggregateSchema($this->aggregate(
            'posts_count',
            AggregateFunction::COUNT,
            guards      : [$rowGuard],
            transformers: [$transformer],
            callerGuards: $deny,
        ));

        $resource = new JsonResource((new User)->setRawAttributes(['aggregate_posts_count' => 2]));

        self::assertInstanceOf(MissingValue::class, $this->resolver->resolveFieldValue('computed', $computed, $resource, null));
        self::assertInstanceOf(MissingValue::class, $this->resolver->resolveFieldValue('accessed', $accessed, $resource, null));
        self::assertSame([], $this->resolver->resolveAggregatesPayload($resource, $schema, 'users', null));
        self::assertSame([], $calls->getArrayCopy());
    }

    /**
     * Test that transformers are applied to the resolved value in order.
     *
     * @return void
     */
    public function testResolveFieldValueAppliesTransformers(): void
    {

        $model = new class {
            /** @var int */
            public int $count = 5;
        };

        $resource   = new JsonResource($model);
        $definition = $this->makeFieldDefinition(
            transformers: [
                static function ($resource, $value): int {
                    self::assertIsInt($value);

                    return $value * 2;
                },
                static function ($resource, $value): int {
                    self::assertIsInt($value);

                    return $value + 1;
                },
            ],
        );

        $result = $this->resolver->resolveFieldValue('count', $definition, $resource, null);

        self::assertSame(11, $result);
    }

    /**
     * Test that a property holding a pure enum case resolves to its lowercase
     * case name, the form the API accepts it in.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsPureEnumAsLowercaseName(): void
    {
        $model = new class {
            /** @var \Tests\Fixtures\Enums\Platform */
            public Platform $platform = Platform::MOBILE_APP;
        };

        $result = $this->resolver->resolveFieldValue('platform', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertSame('mobile_app', $result);
    }

    /**
     * Test that a property holding a backed enum case is left as the case, so
     * it serialises as its backing value.
     *
     * @return void
     */
    public function testResolveFieldValueLeavesBackedEnumUnchanged(): void
    {
        $model = new class {
            /** @var \Tests\Fixtures\Enums\UserStatus */
            public UserStatus $status = UserStatus::BANNED;
        };

        $result = $this->resolver->resolveFieldValue('status', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertSame(UserStatus::BANNED, $result);
    }

    /**
     * Test that a pure enum case returned by a transformer resolves to its
     * lowercase case name, while the transformer itself receives the case.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsTransformedPureEnumAsLowercaseName(): void
    {
        $model = new class {
            /** @var \Tests\Fixtures\Enums\Platform */
            public Platform $platform = Platform::WEB;
        };

        $definition = $this->makeFieldDefinition(
            transformers: [
                static function ($resource, $value): Platform {
                    self::assertSame(Platform::WEB, $value);

                    return Platform::DESKTOP;
                },
            ],
        );

        $result = $this->resolver->resolveFieldValue('platform', $definition, new JsonResource($model), null);

        self::assertSame('desktop', $result);
    }

    /**
     * Test that a MissingValue is returned when the resource wraps a non-object
     * value for simple property resolution.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueForNonObjectResource(): void
    {

        $resource   = new JsonResource(null);
        $definition = $this->makeFieldDefinition();

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a failing guard suppresses a value that would otherwise resolve
     * successfully.
     *
     * @return void
     */
    public function testResolveFieldValueGuardSuppressesResolvableValue(): void
    {

        $model = new class {
            /** @var string */
            public string $name = 'Hidden';
        };

        $resource   = new JsonResource($model);
        $definition = $this->makeFieldDefinition(
            guards: [fn ($resource, $request) => false],
        );

        $result = $this->resolver->resolveFieldValue('name', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a relation definition wraps the loaded related model in the
     * configured child resource class.
     *
     * @return void
     */
    public function testResolveFieldValueWrapsLoadedRelationInChildResource(): void
    {

        $organization = Organization::create([
            'name' => 'ResolverOrg',
            'slug' => 'resolver-org',
        ]);

        $user = User::create([
            'name'            => 'ResolverUser',
            'email'           => 'resolver-user@example.com',
            'organization_id' => $organization->id,
        ]);

        $user->load('organization');

        $resource   = new JsonResource($user);
        $definition = $this->makeFieldDefinition(
            relation: 'organization',
            resource: OrganizationResource::class,
        );

        $result = $this->resolver->resolveFieldValue('organization', $definition, $resource, null);

        self::assertInstanceOf(OrganizationResource::class, $result);
    }

    /**
     * Test that a related collection above the limit is cut to it, in its
     * loaded order, and the cut is reported.
     *
     * @return void
     */
    public function testRelatedCollectionAboveTheLimitIsCutAndReported(): void
    {
        $posts = $this->resolvePosts($this->userWithPosts(4), 3);

        self::assertSame(['Post 1', 'Post 2', 'Post 3'], $posts->map(static fn (mixed $post): mixed => data_get($post, 'resource.title'))->all());
        self::assertTrue($this->resolver->wasTruncated());
    }

    /**
     * Test that a related collection at the limit is kept whole and unflagged.
     *
     * @return void
     */
    public function testRelatedCollectionAtTheLimitIsNotReported(): void
    {
        self::assertCount(4, $this->resolvePosts($this->userWithPosts(4), 4));
        self::assertFalse($this->resolver->wasTruncated());
    }

    /**
     * Test that a disabled default leaves a related collection whole.
     *
     * @return void
     */
    public function testDisabledDefaultLeavesTheRelatedCollectionWhole(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 0);

        self::assertCount(3, $this->resolvePosts($this->userWithPosts(3), null));
        self::assertFalse($this->resolver->wasTruncated());
    }

    /**
     * Test that the truncation report covers only the last resolved field.
     *
     * @return void
     */
    public function testTruncationReportResetsOnTheNextField(): void
    {
        $resource = new JsonResource($this->userWithPosts(2));

        $this->resolver->resolveFieldValue('posts', $this->makeFieldDefinition(relation: 'posts', resource: PostResource::class, limit: 1), $resource, null);

        self::assertTrue($this->resolver->wasTruncated());

        $this->resolver->resolveFieldValue('name', $this->makeFieldDefinition(), $resource, null);

        self::assertFalse($this->resolver->wasTruncated());
    }

    /**
     * Test that a to-one related model is never cut.
     *
     * @return void
     */
    public function testRelatedModelIsNeverCut(): void
    {
        $organization = Organization::create(['name' => 'CapOrg', 'slug' => 'cap-org']);
        $user         = User::create(['name' => 'CapUser', 'email' => 'cap-user@example.com', 'organization_id' => $organization->id]);

        $user->load('organization');

        $definition = $this->makeFieldDefinition(relation: 'organization', resource: OrganizationResource::class, limit: 1);
        $result     = $this->resolver->resolveFieldValue('organization', $definition, new JsonResource($user), null);

        self::assertInstanceOf(OrganizationResource::class, $result);
        self::assertFalse($this->resolver->wasTruncated());
    }

    /**
     * Test that a child resource without withFields is not asked to apply an
     * explicit child field set.
     *
     * @return void
     */
    public function testResolveFieldValueDoesNotCallWithFieldsOnChildResourceWithoutIt(): void
    {
        $organization = Organization::create([
            'name' => 'NoWithFieldsOrg',
            'slug' => 'no-withfields-org',
        ]);

        $user = User::create([
            'name'            => 'NoWithFieldsUser',
            'email'           => 'no-withfields-user@example.com',
            'organization_id' => $organization->id,
        ]);

        $user->load('organization');

        $resource   = new JsonResource($user);
        $definition = $this->makeFieldDefinition(
            relation: 'organization',
            resource: JsonResource::class,
            fields: ['name'],
        );

        self::assertFalse(method_exists(JsonResource::class, 'withFields'));

        $result = $this->resolver->resolveFieldValue('organization', $definition, $resource, null);

        self::assertInstanceOf(JsonResource::class, $result);
    }

    /**
     * Test that transformers are not applied when the resolved value is a
     * MissingValue.
     *
     * @return void
     */
    public function testResolveFieldValueDoesNotTransformMissingValues(): void
    {

        $resource   = new JsonResource(new \stdClass);
        $definition = $this->makeFieldDefinition(
            transformers: [fn ($resource, $value) => 'transformed'],
        );

        $result = $this->resolver->resolveFieldValue('absent_field', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a string compute value pointing to a non-existent method
     * resolves to a MissingValue rather than erroring.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueForUnresolvableCompute(): void
    {

        $resource   = new JsonResource(new \stdClass);
        $definition = $this->makeFieldDefinition(compute: 'thisMethodDoesNotExist');

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a callable accessor resolves via the callable.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsAccessorValueFromCallable(): void
    {

        $resource   = new JsonResource(new \stdClass);
        $definition = new CompiledFieldDefinition(
            accessor: static fn ($resource, $request): string => 'from-callable',
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

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertSame('from-callable', $result);
    }

    /**
     * Test that a non-string, non-callable accessor resolves to a MissingValue
     * rather than erroring.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueForUnresolvableAccessor(): void
    {

        $resource   = new JsonResource(new \stdClass);
        $definition = new CompiledFieldDefinition(
            accessor: 123,
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

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that an Eloquent attribute explicitly set to null resolves to null
     * rather than being treated as missing.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsNullForNullAttribute(): void
    {

        $user = User::create([
            'name'  => 'NullAttr',
            'email' => 'nullattr@example.com',
        ]);

        $user->setAttribute('nickname', null);

        $resource   = new JsonResource($user);
        $definition = $this->makeFieldDefinition();

        $result = $this->resolver->resolveFieldValue('nickname', $definition, $resource, null);

        self::assertNull($result);
    }

    /**
     * Test that properties exposed only through magic __isset/__get are
     * resolved.
     *
     * @return void
     */
    public function testResolveFieldValueResolvesMagicIssetProperties(): void
    {

        $model = new class {
            /**
             * Determine whether a magic property is set.
             *
             * @param  string  $name
             * @return bool
             */
            public function __isset(string $name): bool
            {
                return $name === 'dynamic_field';
            }

            /**
             * Resolve a magic property.
             *
             * @param  string  $name
             * @return mixed
             */
            public function __get(string $name): mixed
            {
                return $name === 'dynamic_field' ? 'dynamic-value' : null;
            }
        };

        $resource   = new JsonResource($model);
        $definition = $this->makeFieldDefinition();

        $result = $this->resolver->resolveFieldValue('dynamic_field', $definition, $resource, null);

        self::assertSame('dynamic-value', $result);
    }

    /**
     * Test that a relation definition resolves to a MissingValue when the
     * relation has not been loaded.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueWhenRelationNotLoaded(): void
    {

        $organization = Organization::create([
            'name' => 'UnloadedOrg',
            'slug' => 'unloaded-org',
        ]);

        $user = User::create([
            'name'            => 'UnloadedUser',
            'email'           => 'unloaded-user@example.com',
            'organization_id' => $organization->id,
        ]);

        $resource   = new JsonResource($user);
        $definition = $this->makeFieldDefinition(
            relation: 'organization',
            resource: OrganizationResource::class,
        );

        $result = $this->resolver->resolveFieldValue('organization', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a relation definition resolves to a MissingValue when the owner
     * cannot report relation load state.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueWhenOwnerCannotReportRelations(): void
    {

        $model = new class {
            /**
             * Return a loaded relation value.
             *
             * @param  string  $name
             * @return mixed
             */
            public function getRelation(string $name): mixed
            {
                return null;
            }
        };

        $resource   = new JsonResource($model);
        $definition = $this->makeFieldDefinition(
            relation: 'organization',
            resource: OrganizationResource::class,
        );

        $result = $this->resolver->resolveFieldValue('organization', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a relation definition resolves to a MissingValue when the
     * resource wraps a non-object value.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueForRelationOnNullResource(): void
    {

        $resource   = new JsonResource(null);
        $definition = $this->makeFieldDefinition(
            relation: 'organization',
            resource: OrganizationResource::class,
        );

        $result = $this->resolver->resolveFieldValue('organization', $definition, $resource, null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that wrapping a loaded relation does not eager load missing
     * relations on the related model.
     *
     * @return void
     */
    public function testResolveFieldValueDoesNotEagerLoadWhenWrappingRelations(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('users')
            ->andReturn(['id', 'organization']);

        ApiQuery::shouldReceive('getFields')
            ->with('organizations')
            ->andReturn(null);

        $organization = Organization::create([
            'name' => 'WrapLazyOrg',
            'slug' => 'wrap-lazy-org',
        ]);

        $user = User::create([
            'name'            => 'WrapLazyUser',
            'email'           => 'wrap-lazy-user@example.com',
            'organization_id' => $organization->id,
        ]);

        $post = Post::create([
            'user_id'   => $user->id,
            'title'     => 'Wrapped Post',
            'body'      => 'Body',
            'published' => true,
        ]);

        $post->load('user');

        $resource   = new JsonResource($post);
        $definition = $this->makeFieldDefinition(
            relation: 'user',
            resource: UserResource::class,
        );

        $result = $this->resolver->resolveFieldValue('user', $definition, $resource, null);

        self::assertInstanceOf(UserResource::class, $result);

        $related = $post->getRelation('user');

        self::assertInstanceOf(User::class, $related);
        self::assertFalse($related->relationLoaded('organization'));
    }

    /**
     * Test that explicit child fields on a relation definition are applied to
     * wrapped relation collections.
     *
     * @return void
     */
    public function testResolveFieldValueAppliesExplicitChildFieldsToCollections(): void
    {

        ApiQuery::shouldReceive('getFields')
            ->with('posts')
            ->andReturn(null);

        $user = User::create([
            'name'  => 'CollectionFields',
            'email' => 'collectionfields@example.com',
        ]);

        Post::create([
            'user_id'   => $user->id,
            'title'     => 'Projected Post',
            'body'      => 'Body',
            'published' => true,
        ]);

        $user->load('posts');

        $resource   = new JsonResource($user);
        $definition = $this->makeFieldDefinition(
            relation: 'posts',
            resource: PostResource::class,
            fields: ['body'],
        );

        $result = $this->resolver->resolveFieldValue('posts', $definition, $resource, null);

        self::assertInstanceOf(ApiResourceCollection::class, $result);

        $items = $result->toArray(Request::create('/', 'GET'));

        self::assertCount(1, $items);
        self::assertArrayHasKey('body', $items[0]);
        self::assertArrayNotHasKey('title', $items[0]);
    }

    /**
     * Test that a field listed in the model's appended attributes resolves
     * through the magic getter.
     *
     * @return void
     */
    public function testResolveFieldValueResolvesAppendedAttribute(): void
    {

        $model = new class {
            /** @var array<int, string> */
            public array $appends = ['full_name'];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }

            /**
             * Resolve the appended attribute.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return 'Appended Value';
            }
        };

        $result = $this->resolver->resolveFieldValue('full_name', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertSame('Appended Value', $result);
    }

    /**
     * Test that a field with no property, attribute, append, cast accessor or
     * magic isset resolves to a MissingValue.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueWhenNoAccessiblePathExists(): void
    {

        $model = new class {
            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }
        };

        $result = $this->resolver->resolveFieldValue('ghost_field', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertInstanceOf(MissingValue::class, $result);
    }

    /**
     * Test that a relation field carrying a string accessor reads the accessor
     * path from the loaded related model.
     *
     * @return void
     */
    public function testResolveFieldValueReadsStringAccessorFromLoadedRelation(): void
    {

        $resource   = new JsonResource($this->makeRelationOwner((object) ['label' => 'RelatedLabel']));
        $definition = $this->makeFieldDefinition(accessor: 'label', relation: 'thing');

        $result = $this->resolver->resolveFieldValue('thing', $definition, $resource, null);

        self::assertSame('RelatedLabel', $result);
    }

    /**
     * Test that a relation field carrying a callable accessor invokes the
     * callable with the resource.
     *
     * @return void
     */
    public function testResolveFieldValueInvokesCallableAccessorForLoadedRelation(): void
    {

        $resource   = new JsonResource($this->makeRelationOwner((object) ['label' => 'ignored']));
        $definition = new CompiledFieldDefinition(
            accessor: static fn ($resource, $request): string => 'from-relation-callable',
            compute: null,
            relation: 'thing',
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );

        $result = $this->resolver->resolveFieldValue('thing', $definition, $resource, null);

        self::assertSame('from-relation-callable', $result);
    }

    /**
     * Test that a relation field naming neither an accessor nor a resource
     * resolves to the loaded related value as it is.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsTheLoadedRelationWithoutAResource(): void
    {
        $related = (object) ['label' => 'Related'];

        $result = $this->resolver->resolveFieldValue('thing', $this->makeFieldDefinition(relation: 'thing'), new JsonResource($this->makeRelationOwner($related)), null);

        self::assertSame($related, $result);
    }

    /**
     * Test that a relation field carrying a non-string, non-callable accessor
     * resolves to null rather than erroring.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsNullForUnresolvableRelationAccessor(): void
    {

        $resource   = new JsonResource($this->makeRelationOwner((object) ['label' => 'ignored']));
        $definition = new CompiledFieldDefinition(
            accessor: 123,
            compute: null,
            relation: 'thing',
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );

        $result = $this->resolver->resolveFieldValue('thing', $definition, $resource, null);

        self::assertNull($result);
    }

    /**
     * Test that each function reads its raw value with its own cast, that a
     * column aggregate loaded as null renders null, and that one never loaded
     * is left out.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadReadsEachFunction(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner = (new User)->setRawAttributes([
            'aggregate_posts_count'  => '3',
            'aggregate_posts_exists' => '0',
            'aggregate_has_posts'    => 1,
            'aggregate_posts_sum_id' => '15',
            'aggregate_posts_avg_id' => 7,
            'aggregate_posts_max_id' => null,
        ]);

        $schema = $this->aggregateSchema(
            $this->aggregate('posts_count', AggregateFunction::COUNT),
            $this->aggregate('posts_exists', AggregateFunction::EXISTS),
            $this->aggregate('has_posts', AggregateFunction::EXISTS),
            $this->aggregate('posts_sum_id', AggregateFunction::SUM, 'id'),
            $this->aggregate('posts_avg_id', AggregateFunction::AVG, 'id'),
            $this->aggregate('posts_max_id', AggregateFunction::MAX, 'id'),
            $this->aggregate('posts_min_id', AggregateFunction::MIN, 'id'),
        );

        self::assertSame([
            'posts_count'  => 3,
            'posts_exists' => false,
            'has_posts'    => true,
            'posts_sum_id' => 15.0,
            'posts_avg_id' => 7.0,
            'posts_max_id' => null,
        ], $this->resolver->resolveAggregatesPayload(new JsonResource($owner), $schema, 'users', null));
    }

    /**
     * Test that a count or existence check loaded as null is left out, since
     * neither documents a null.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadLeavesOutANullCountOrExistenceCheck(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner  = (new User)->setRawAttributes(['aggregate_posts_count' => null, 'aggregate_posts_exists' => null]);
        $schema = $this->aggregateSchema(
            $this->aggregate('posts_count', AggregateFunction::COUNT),
            $this->aggregate('posts_exists', AggregateFunction::EXISTS),
        );

        self::assertSame([], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));
    }

    /**
     * Test that a transformer receives a column aggregate's null, as a field
     * transformer receives a null field.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadPassesANullToItsTransformers(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner  = (new User)->setRawAttributes(['aggregate_posts_sum_id' => null]);
        $schema = $this->aggregateSchema($this->aggregate('posts_sum_id', AggregateFunction::SUM, 'id', transformers: [
            static fn (mixed $resource, mixed $value): string => get_debug_type($value),
        ]));

        self::assertSame(['posts_sum_id' => 'null'], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));
    }

    /**
     * Test that a request selects exactly its keys, and that with none the
     * defaults are selected.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadSelectsRequestedKeysElseDefaults(): void
    {
        $owner  = (new User)->setRawAttributes(['aggregate_posts_count' => 2, 'aggregate_posts_sum_id' => 9]);
        $schema = $this->aggregateSchema(
            $this->aggregate('posts_count', AggregateFunction::COUNT),
            $this->aggregate('posts_sum_id', AggregateFunction::SUM, 'id', isDefault: false),
        );

        ApiQuery::shouldReceive('getAggregates')->with('users')->once()->andReturn(null);
        ApiQuery::shouldReceive('getAggregates')->with('accounts')->once()->andReturn(['posts_sum_id', 'unknown']);

        self::assertSame(['posts_count' => 2], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));
        self::assertSame(['posts_sum_id' => 9.0], $this->resolver->resolveAggregatesPayload($owner, $schema, 'accounts', null));
    }

    /**
     * Test that a guarded aggregate is skipped while the next one resolves.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadSkipsAnAggregateFailingItsGuard(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner  = (new User)->setRawAttributes(['aggregate_hidden' => 4, 'aggregate_posts_count' => 2]);
        $schema = $this->aggregateSchema(
            $this->aggregate('hidden', AggregateFunction::COUNT, guards: [static fn (): bool => false]),
            $this->aggregate('posts_count', AggregateFunction::COUNT, guards: [static fn (): bool => true]),
        );

        self::assertSame(['posts_count' => 2], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));
    }

    /**
     * Test that a resource without an attribute-bearing model yields nothing.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadReturnsEmptyWithoutAttributes(): void
    {
        $schema = $this->aggregateSchema($this->aggregate('posts_count', AggregateFunction::COUNT));

        self::assertSame([], $this->resolver->resolveAggregatesPayload(new JsonResource(null), $schema, 'users', null));
        self::assertSame([], $this->resolver->resolveAggregatesPayload(new \stdClass, $schema, 'users', null));
    }

    /**
     * Test that the value is read raw, never through an owner accessor named
     * like the loaded attribute, which supplies nothing when it is not loaded.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadNeverInvokesAnOwnerAccessor(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner = new class extends Model {
            /**
             * Shadow the loaded aggregate attribute.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            protected function aggregatePostsCount(): Attribute
            {
                return Attribute::make(get: static fn (): int => 999);
            }
        };

        $schema = $this->aggregateSchema($this->aggregate('posts_count', AggregateFunction::COUNT));

        self::assertSame([], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));

        $owner->setRawAttributes(['aggregate_posts_count' => '3']);

        self::assertSame(['posts_count' => 3], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));
    }

    /**
     * Test that a maximum over an uncast timestamp reads as a date, so it
     * renders as ISO 8601 like the same column does as a field.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadReadsAnUncastTimestampAsADate(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner  = (new User)->setRawAttributes(['aggregate_posts_max_created_at' => '2026-01-01 10:00:00']);
        $schema = $this->aggregateSchema($this->aggregate('posts_max_created_at', AggregateFunction::MAX, 'created_at'));

        $payload = $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null);

        self::assertInstanceOf(\DateTimeInterface::class, $payload['posts_max_created_at']);
        self::assertSame('{"posts_max_created_at":"2026-01-01T10:00:00.000000Z"}', json_encode($payload));
    }

    /**
     * Test that a minimum and a maximum are read through the related model's
     * own date format and casts.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadReadsThroughTheRelatedModel(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('owners')->andReturn(null);

        $owner = (new AggregateOwner)->setRawAttributes([
            'aggregate_lines_max_created_at' => '15/01/2026 10:30',
            'aggregate_lines_min_status'     => 'active',
            'aggregate_lines_min_price'      => '12.5',
            'aggregate_lines_max_quantity'   => '4',
        ]);

        $schema = $this->aggregateSchema(
            $this->aggregate('lines_max_created_at', AggregateFunction::MAX, 'created_at', 'lines'),
            $this->aggregate('lines_min_status', AggregateFunction::MIN, 'status', 'lines'),
            $this->aggregate('lines_min_price', AggregateFunction::MIN, 'price', 'lines'),
            $this->aggregate('lines_max_quantity', AggregateFunction::MAX, 'quantity', 'lines'),
        );

        $payload = $this->resolver->resolveAggregatesPayload($owner, $schema, 'owners', null);

        self::assertInstanceOf(\DateTimeInterface::class, $payload['lines_max_created_at']);
        self::assertSame('2026-01-15 10:30', $payload['lines_max_created_at']->format('Y-m-d H:i'));
        self::assertSame(UserStatus::ACTIVE, $payload['lines_min_status']);
        self::assertSame('12.50', $payload['lines_min_price']);
        self::assertSame(4, $payload['lines_max_quantity']);
        self::assertSame(['lines' => AggregateLine::class], $this->getStaticProperty(ValueResolver::class, 'relatedModelCache')[AggregateOwner::class]);
    }

    /**
     * Test that a transformer receives the value after the related cast.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadRunsTransformersAfterTheCast(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('owners')->andReturn(null);

        $owner  = (new AggregateOwner)->setRawAttributes(['aggregate_lines_min_status' => 'banned', 'aggregate_lines_count' => '2']);
        $schema = $this->aggregateSchema(
            $this->aggregate('lines_min_status', AggregateFunction::MIN, 'status', 'lines', transformers: [
                static fn (mixed $resource, mixed $value): string => $value instanceof UserStatus ? $value->name : 'raw',
            ]),
            $this->aggregate('lines_count', AggregateFunction::COUNT, relation: 'lines', transformers: [
                static fn (mixed $resource, mixed $value): mixed => is_int($value) ? $value * 10 : $value,
                static fn (mixed $resource, mixed $value): mixed => is_int($value) ? $value + 1 : $value,
            ]),
        );

        self::assertSame(['lines_min_status' => 'BANNED', 'lines_count' => 21], $this->resolver->resolveAggregatesPayload($owner, $schema, 'owners', null));
    }

    /**
     * Test that a guard hides a minimum before the related cast reads it, so a
     * value the cast refuses never surfaces.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadGuardsAMinimumBeforeReadingIt(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('owners')->andReturn(null);

        $owner  = (new AggregateOwner)->setRawAttributes(['aggregate_hidden_status' => 'bogus', 'aggregate_withheld_status' => 'bogus']);
        $schema = $this->aggregateSchema(
            $this->aggregate('hidden_status', AggregateFunction::MIN, 'status', 'lines', guards: [static fn (): bool => false]),
            $this->aggregate('withheld_status', AggregateFunction::MAX, 'status', 'lines', callerGuards: [static fn (): bool => false]),
        );

        self::assertSame([], $this->resolver->resolveAggregatesPayload($owner, $schema, 'owners', null));
    }

    /**
     * Test that a minimum over a relation the owner cannot resolve is returned
     * raw, and the miss is memoised.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadReturnsTheRawValueWithoutARelatedModel(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('users')->andReturn(null);

        $owner = new class extends Model {
            /**
             * A method that is not a relation.
             *
             * @return string
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function notARelation(): string
            {
                return 'nope';
            }
        };

        $owner->setRawAttributes(['aggregate_missing_min' => '2026-01-01', 'aggregate_plain_min' => '5']);

        $schema = $this->aggregateSchema(
            $this->aggregate('missing_min', AggregateFunction::MIN, 'created_at', 'missing'),
            $this->aggregate('plain_min', AggregateFunction::MIN, 'created_at', 'notARelation'),
        );

        self::assertSame(['missing_min' => '2026-01-01', 'plain_min' => '5'], $this->resolver->resolveAggregatesPayload($owner, $schema, 'users', null));
        self::assertSame(['missing' => null, 'notARelation' => null], $this->getStaticProperty(ValueResolver::class, 'relatedModelCache')[$owner::class]);
    }

    /**
     * Test that a memoised related model is honoured, so the owner's relation
     * is not resolved again.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadHonoursTheMemoisedRelatedModel(): void
    {
        ApiQuery::shouldReceive('getAggregates')->with('owners')->andReturn(null);

        $this->setStaticProperty(ValueResolver::class, 'relatedModelCache', [AggregateOwner::class => ['lines' => null]]);

        $owner  = (new AggregateOwner)->setRawAttributes(['aggregate_lines_min_status' => 'active']);
        $schema = $this->aggregateSchema($this->aggregate('lines_min_status', AggregateFunction::MIN, 'status', 'lines'));

        self::assertSame(['lines_min_status' => 'active'], $this->resolver->resolveAggregatesPayload($owner, $schema, 'owners', null));
    }

    /**
     * Build a compiled schema holding the given aggregates by present key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  ...$aggregates
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function aggregateSchema(CompiledAggregateDefinition ...$aggregates): CompiledSchema
    {
        $keyed = [];

        foreach ($aggregates as $aggregate) {
            $keyed[$aggregate->presentKey] = $aggregate;
        }

        return new CompiledSchema([], $keyed);
    }

    /**
     * Build a compiled aggregate definition, a default unless stated.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string|null  $column
     * @param  string  $relation
     * @param  bool  $isDefault
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  array<int, callable(mixed): bool>  $callerGuards
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function aggregate(
        string $key,
        AggregateFunction $function,
        ?string $column = null,
        string $relation = 'posts',
        bool $isDefault = true,
        array $guards = [],
        array $transformers = [],
        array $callerGuards = [],
    ): CompiledAggregateDefinition {
        return new CompiledAggregateDefinition(
            presentKey: $key,
            relation: $relation,
            function: $function,
            column: $column,
            constraint: null,
            isDefault: $isDefault,
            guards: $guards,
            transformers: $transformers,
            callerGuards: $callerGuards,
        );
    }

    /**
     * Build an owner exposing a single already-loaded relation.
     *
     * @param  object  $related
     * @return object
     */
    private function makeRelationOwner(object $related): object
    {
        return new class ($related) {
            /**
             * @param  object  $related
             */
            public function __construct(

                /** The related value returned for the loaded relation. */
                private readonly object $related,
            ) {}

            /**
             * Report the relation as loaded.
             *
             * @param  string  $name
             * @return bool
             */
            public function relationLoaded(string $name): bool // phpcs:ignore SineMacula.NamingConventions.BooleanMethodName.NotPredicate
            {
                return true;
            }

            /**
             * Return the loaded relation value.
             *
             * @param  string  $name
             * @return object
             */
            public function getRelation(string $name): object
            {
                return $this->related;
            }
        };
    }

    /**
     * Resolve the user's posts through the post resource under the given limit.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @param  int|null  $limit
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    private function resolvePosts(User $user, ?int $limit): Collection
    {
        $definition = $this->makeFieldDefinition(relation: 'posts', resource: PostResource::class, limit: $limit);
        $result     = $this->resolver->resolveFieldValue('posts', $definition, new JsonResource($user), null);

        self::assertInstanceOf(ApiResourceCollection::class, $result);
        self::assertInstanceOf(Collection::class, $result->collection);

        return $result->collection;
    }

    /**
     * Resolve the post's tags over the given relation, rendering their role.
     *
     * @param  \Tests\Fixtures\Models\Post  $post
     * @param  string  $relation
     * @return array<int, array<string, mixed>>
     */
    private function resolveTags(Post $post, string $relation): array
    {
        $definition = $this->makeFieldDefinition(relation: $relation, resource: TagResource::class, fields: ['id'], pivot: ['role']);
        $result     = $this->resolver->resolveFieldValue($relation, $definition, new JsonResource($post), null);

        self::assertInstanceOf(ApiResourceCollection::class, $result);

        /** @var array<int, array<string, mixed>> */
        return $result->resolve();
    }

    /**
     * Build a post whose tags and typed tags each hold one tag carrying a pivot
     * of the given attributes under the given accessor.
     *
     * @param  string  $accessor
     * @param  array<string, mixed>  $attributes
     * @return \Tests\Fixtures\Models\Post
     */
    private function postWithTags(string $accessor, array $attributes): Post
    {
        $post = (new Post)->forceFill(['id' => 1]);
        $tag  = (new Tag)->forceFill(['id' => 1, 'name' => 'php'])->setRelation($accessor, Pivot::fromRawAttributes($post, $attributes, 'post_tag', true));

        return $post->setRelation('tags', new EloquentCollection([$tag]))->setRelation('typedTags', new EloquentCollection([$tag]));
    }

    /**
     * Create a user with the given number of posts loaded.
     *
     * @param  int  $count
     * @return \Tests\Fixtures\Models\User
     */
    private function userWithPosts(int $count): User
    {
        $user = User::create(['name' => 'Poster', 'email' => 'poster@example.com']);

        for ($index = 1; $index <= $count; $index++) {
            Post::create(['user_id' => $user->id, 'title' => 'Post ' . $index, 'body' => 'Body']);
        }

        return $user->load(['posts' => static fn ($query) => $query->orderBy('id')]);
    }

    /**
     * Render a loaded organization relation with the given explicit child
     * fields.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function renderOrganizationWithChildFields(array $fields): array
    {
        $organization = Organization::create(['name' => 'ChildFieldsOrg', 'slug' => 'child-fields-org']);

        $user = User::create([
            'name'            => 'ChildFieldsUser',
            'email'           => 'child-fields-user@example.com',
            'organization_id' => $organization->id,
        ]);

        $user->load('organization');

        $definition = $this->makeFieldDefinition(
            relation: 'organization',
            resource: OrganizationResource::class,
            fields: $fields,
        );

        $result = $this->resolver->resolveFieldValue('organization', $definition, new JsonResource($user), null);

        self::assertInstanceOf(OrganizationResource::class, $result);

        return $result->resolve();
    }

    /**
     * Create a compiled field definition with the given overrides.
     *
     * @param  string|null  $accessor
     * @param  mixed  $compute
     * @param  string|null  $relation
     * @param  string|null  $resource
     * @param  array<int, string>|null  $fields
     * @param  (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null  $constraint
     * @param  array<int, string>  $extras
     * @param  array<int, string>  $needs
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  int|null  $limit
     * @param  array<int, callable(mixed): bool>  $callerGuards
     * @param  array<int, string>  $pivot
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeFieldDefinition(
        ?string $accessor = null,
        mixed $compute = null,
        ?string $relation = null,
        ?string $resource = null,
        ?array $fields = null,
        ?\Closure $constraint = null,
        array $extras = [],
        array $needs = [],
        array $guards = [],
        array $transformers = [],
        ?int $limit = null,
        array $callerGuards = [],
        array $pivot = [],
    ): CompiledFieldDefinition {
        return new CompiledFieldDefinition(
            accessor: $accessor,
            compute: $compute,
            relation: $relation,
            resource: $resource,
            fields: $fields,
            constraint: $constraint,
            extras: $extras,
            needs: $needs,
            guards: $guards,
            transformers: $transformers,
            limit: $limit,
            callerGuards: $callerGuards,
            pivot: $pivot,
        );
    }
}
