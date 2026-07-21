<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValueResolver field and count resolution class.
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

        // Seed a decision reflection would never produce: label() returns
        // string, not Attribute, so an honoured memo yields the __get value
        // while a recomputation would yield MissingValue.
        $this->setStaticProperty(ValueResolver::class, 'castAccessorCache', [$model::class => ['label' => true]]);

        $result = $this->resolver->resolveFieldValue('label', $this->makeFieldDefinition(), new JsonResource($model), null);

        self::assertSame('from-memo', $result);
    }

    /**
     * Test that a method whose return type is a named type other than Attribute
     * is not treated as a cast accessor, so the field resolves to MissingValue
     * and the memo records the rejection.
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
     * Test that the resolved child field set is memoised per compiled
     * definition.
     *
     * @return void
     */
    public function testRelationFieldsAreMemoisedPerDefinition(): void
    {
        // A leading empty entry makes the array_values re-index observable: a
        // plain array_filter would leave the kept entries on keys 1 and 2.
        $definition = $this->makeFieldDefinition(fields: ['', 'id', 'name']);

        $first  = $this->invokeMethod($this->resolver, 'getRelationFields', $definition);
        $second = $this->invokeMethod($this->resolver, 'getRelationFields', $definition);

        self::assertSame(['id', 'name'], $first);
        self::assertSame($first, $second);

        $cache = $this->getStaticProperty(ValueResolver::class, 'relationFieldsCache');

        self::assertInstanceOf(\WeakMap::class, $cache);
        self::assertCount(1, $cache);
        self::assertTrue($cache->offsetExists($definition));
    }

    /**
     * Test that a seeded child-field memo is consulted rather than recomputed.
     *
     * @return void
     */
    public function testRelationFieldsMemoIsConsulted(): void
    {
        $definition = $this->makeFieldDefinition(fields: ['id', 'name']);

        $weakMap              = new \WeakMap;
        $weakMap[$definition] = ['sentinel_field'];

        $this->setStaticProperty(ValueResolver::class, 'relationFieldsCache', $weakMap);

        $result = $this->invokeMethod($this->resolver, 'getRelationFields', $definition);

        self::assertSame(['sentinel_field'], $result);
    }

    /**
     * Test that clearing the cache empties both serialization memo caches.
     *
     * @return void
     */
    public function testClearCacheEmptiesMemoCaches(): void
    {
        $definition           = $this->makeFieldDefinition(fields: ['id']);
        $weakMap              = new \WeakMap;
        $weakMap[$definition] = ['id'];

        $this->setStaticProperty(ValueResolver::class, 'castAccessorCache', ['Foo' => ['bar' => true]]);
        $this->setStaticProperty(ValueResolver::class, 'relationFieldsCache', $weakMap);

        ValueResolver::clearCache();

        self::assertSame([], $this->getStaticProperty(ValueResolver::class, 'castAccessorCache'));
        self::assertNull($this->getStaticProperty(ValueResolver::class, 'relationFieldsCache'));
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
                fn ($resource, $value) => $value * 2,
                fn ($resource, $value) => $value + 1,
            ],
        );

        $result = $this->resolver->resolveFieldValue('count', $definition, $resource, null);

        self::assertSame(11, $result);
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
     * Test that count definitions with isDefault=true are included when no
     * specific counts are requested.
     *
     * @return void
     */
    public function testResolveCountsPayloadReturnsCountsForDefaultDefinitions(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_count' => 10];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts' => new CompiledCountDefinition('posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['posts' => 10], $result);
    }

    /**
     * Test that two counts on the same relation but with different presentation
     * keys resolve to their own `{presentKey}_count` attributes rather than
     * colliding on a single `{relation}_count` value.
     *
     * @return void
     */
    public function testResolveCountsPayloadDisambiguatesCountsOnTheSameRelation(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_count' => 10, 'active_posts_count' => 3];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts'        => new CompiledCountDefinition('posts', 'posts', null, true, []),
            'active_posts' => new CompiledCountDefinition('active_posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['posts' => 10, 'active_posts' => 3], $result);
    }

    /**
     * Test that only explicitly requested count aliases are included.
     *
     * @return void
     */
    public function testResolveCountsPayloadReturnsCountsForRequestedAliases(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(['comments']);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = [
                'posts_count'    => 5,
                'comments_count' => 12,
            ];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts'    => new CompiledCountDefinition('posts', 'posts', null, true, []),
            'comments' => new CompiledCountDefinition('comments', 'comments', null, false, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['comments' => 12], $result);
    }

    /**
     * Test that count definitions failing guard evaluation are excluded.
     *
     * @return void
     */
    public function testResolveCountsPayloadExcludesCountsFailingGuards(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_count' => 10];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts' => new CompiledCountDefinition('posts', 'posts', null, true, [
                fn ($resource, $request) => false,
            ]),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that a non-object resource returns an empty counts array.
     *
     * @return void
     */
    public function testResolveCountsPayloadReturnsEmptyForNonObjectResource(): void
    {

        $resource = new JsonResource(null);
        $schema   = new CompiledSchema([], [
            'posts' => new CompiledCountDefinition('posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame([], $result);
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
     * Test that wrapping a single related model in a child resource that does
     * not expose withFields does not attempt to call it, even when an explicit
     * child field set is declared.
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
     * Test that a count definition failing its guard is skipped while later
     * definitions are still resolved.
     *
     * @return void
     */
    public function testResolveCountsPayloadContinuesAfterGuardedCount(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = [
                'secret_count' => 1,
                'posts_count'  => 4,
            ];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'secret' => new CompiledCountDefinition('secret', 'secret', null, true, [
                fn ($resource, $request) => false,
            ]),
            'posts' => new CompiledCountDefinition('posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['posts' => 4], $result);
    }

    /**
     * Test that all resolved counts are returned and values are cast to
     * integers.
     *
     * @return void
     */
    public function testResolveCountsPayloadCastsValuesAndReturnsAllCounts(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = [
                'posts_count'    => '7',
                'comments_count' => 3,
            ];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts'    => new CompiledCountDefinition('posts', 'posts', null, true, []),
            'comments' => new CompiledCountDefinition('comments', 'comments', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['posts' => 7, 'comments' => 3], $result);
    }

    /**
     * Test that sum aggregate definitions with isDefault=true are resolved when
     * no specific sums are requested.
     *
     * @return void
     */
    public function testResolveSumsPayloadReturnsDefaultSumsWhenNoneRequested(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => '15'];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame(['posts_id' => 15.0], $result);
    }

    /**
     * Test that only explicitly requested relation-column sums are included.
     *
     * @return void
     */
    public function testResolveSumsPayloadReturnsOnlyRequestedSums(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(['posts' => ['id']]);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => 10];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, false, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame(['posts_id' => 10.0], $result);
    }

    /**
     * Test that sum definitions failing guard evaluation are excluded.
     *
     * @return void
     */
    public function testResolveSumsPayloadExcludesSumsFailingGuards(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => 5];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, [
                fn ($resource, $request) => false,
            ]),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that a non-object resource returns an empty sums array.
     *
     * @return void
     */
    public function testResolveSumsPayloadReturnsEmptyForNonObjectResource(): void
    {
        $resource = new JsonResource(null);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that avg aggregate definitions with isDefault=true are resolved when
     * no specific averages are requested.
     *
     * @return void
     */
    public function testResolveAveragesPayloadReturnsDefaultAvgsWhenNoneRequested(): void
    {

        ApiQuery::shouldReceive('getAverages')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_avg_id' => '3.5'];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'avg', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('avg', $resource, $schema, 'users', null);

        self::assertSame(['posts_id' => 3.5], $result);
    }

    /**
     * Test that average definitions failing guard evaluation are excluded.
     *
     * @return void
     */
    public function testResolveAveragesPayloadExcludesAvgsFailingGuards(): void
    {

        ApiQuery::shouldReceive('getAverages')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_avg_id' => 2];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'avg', null, true, [
                fn ($resource, $request) => false,
            ]),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('avg', $resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that a non-object resource returns an empty averages array.
     *
     * @return void
     */
    public function testResolveAveragesPayloadReturnsEmptyForNonObjectResource(): void
    {
        $resource = new JsonResource(null);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'avg', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('avg', $resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that an avg entry with a metric='sum' is skipped by the averages
     * resolver (metric discriminator is enforced).
     *
     * @return void
     */
    public function testResolveAveragesPayloadSkipsSumMetrics(): void
    {

        ApiQuery::shouldReceive('getAverages')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = [];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('avg', $resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that aggregate definitions for relations not present in the
     * requested map are excluded without a type error on null column list.
     *
     * When getSums returns a non-empty map, relations absent from that map
     * resolve to a null column list. The isIncluded closure must short-circuit
     * on is_array(null) before reaching in_array, otherwise a TypeError occurs.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadExcludesNonRequestedRelationsWithoutError(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(['posts' => ['id']]);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => 15, 'comments_id_sum_id' => 7];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id'    => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, false, []),
            'comments_id' => new CompiledAggregateDefinition('comments_id', 'comments', 'id', 'sum', null, false, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame(['posts_id' => 15.0], $result);
    }

    /**
     * Test that the aggregate loop continues past a definition whose guard
     * fails and still resolves later definitions that pass.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadContinuesPastDefinitionFailingGuard(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => 3, 'tags_id_sum_id' => 9];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, [
                fn ($resource, $request) => false,
            ]),
            'tags_id' => new CompiledAggregateDefinition('tags_id', 'tags', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame(['tags_id' => 9.0], $result);
    }

    /**
     * Test that all included aggregate definitions are returned when multiple
     * definitions pass inclusion and guard checks.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadReturnsAllIncludedDefinitions(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => 5, 'comments_id_sum_id' => 8];

            /**
             * Return the attributes array.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id'    => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, []),
            'comments_id' => new CompiledAggregateDefinition('comments_id', 'comments', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertCount(2, $result);
        self::assertSame(5.0, $result['posts_id']);
        self::assertSame(8.0, $result['comments_id']);
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
     * Test that a field with no property, attribute, appended entry, cast
     * accessor method, or magic isset resolves to a MissingValue.
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
     * Test that a count attribute exposed only through magic isset and get is
     * read and included in the payload.
     *
     * @return void
     */
    public function testResolveCountsPayloadReadsAttributeViaMagicIsset(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /**
             * Return the attributes array without the count attribute.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }

            /**
             * Report the count attribute present through magic isset.
             *
             * @param  string  $key
             * @return bool
             */
            public function __isset(string $key): bool
            {
                return $key === 'posts_count';
            }

            /**
             * Resolve the count attribute through the magic getter.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $key === 'posts_count' ? 8 : null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts' => new CompiledCountDefinition('posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['posts' => 8], $result);
    }

    /**
     * Test that a count whose attribute is not loaded at all is skipped.
     *
     * @return void
     */
    public function testResolveCountsPayloadSkipsCountWhenAttributeAbsent(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /**
             * Return the attributes array without the count attribute.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'posts' => new CompiledCountDefinition('posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that an aggregate whose attribute is not loaded at all is skipped.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadSkipsAggregateWhenAttributeAbsent(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /**
             * Return the attributes array without the aggregate attribute.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'posts_id' => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame([], $result);
    }

    /**
     * Test that a count whose attribute is not loaded is skipped while a later
     * count on the same payload is still resolved.
     *
     * @return void
     */
    public function testResolveCountsPayloadContinuesAfterNullValuedCount(): void
    {

        ApiQuery::shouldReceive('getCounts')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_count' => 4];

            /**
             * Return the attributes array carrying only the later count.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [
            'missing' => new CompiledCountDefinition('missing', 'missing', null, true, []),
            'posts'   => new CompiledCountDefinition('posts', 'posts', null, true, []),
        ]);

        $result = $this->resolver->resolveCountsPayload($resource, $schema, 'users', null);

        self::assertSame(['posts' => 4], $result);
    }

    /**
     * Test that an aggregate whose attribute is not loaded is skipped while a
     * later aggregate on the same payload is still resolved.
     *
     * @return void
     */
    public function testResolveAggregatesPayloadContinuesAfterNullValuedAggregate(): void
    {

        ApiQuery::shouldReceive('getSums')
            ->with('users')
            ->andReturn(null);

        $model = new class {
            /** @var array<string, mixed> */
            private array $attributes = ['posts_id_sum_id' => '20'];

            /**
             * Return the attributes array carrying only the later aggregate.
             *
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /**
             * Magic getter to resolve attributes.
             *
             * @param  string  $key
             * @return mixed
             */
            public function __get(string $key): mixed
            {
                return $this->attributes[$key] ?? null;
            }
        };

        $resource = new JsonResource($model);
        $schema   = new CompiledSchema([], [], [
            'missing_id' => new CompiledAggregateDefinition('missing_id', 'missing', 'id', 'sum', null, true, []),
            'posts_id'   => new CompiledAggregateDefinition('posts_id', 'posts', 'id', 'sum', null, true, []),
        ]);

        $result = $this->resolver->resolveAggregatesPayload('sum', $resource, $schema, 'users', null);

        self::assertSame(['posts_id' => 20.0], $result);
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
     * Create a compiled field definition with the given overrides.
     *
     * @param  string|null  $accessor
     * @param  mixed  $compute
     * @param  string|null  $relation
     * @param  string|null  $resource
     * @param  array<int, string>|null  $fields
     * @param  \Closure(mixed): mixed|null  $constraint
     * @param  array<int, string>  $extras
     * @param  array<int, string>  $needs
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
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
        );
    }
}
