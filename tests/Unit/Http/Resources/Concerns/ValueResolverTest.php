<?php

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
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
class ValueResolverTest extends TestCase
{
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

        $this->resolver = new ValueResolver(new GuardEvaluator);
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

        static::assertSame('Alice', $result);
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

        static::assertSame('computed-value', $result);
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

        static::assertSame('method-computed', $result);
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

        static::assertSame('nested-value', $result);
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

        static::assertInstanceOf(MissingValue::class, $result);
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

        static::assertSame(11, $result);
    }

    /**
     * Test that a MissingValue is returned when the resource wraps a
     * non-object value for simple property resolution.
     *
     * @return void
     */
    public function testResolveFieldValueReturnsMissingValueForNonObjectResource(): void
    {

        $resource   = new JsonResource(null);
        $definition = $this->makeFieldDefinition();

        $result = $this->resolver->resolveFieldValue('field', $definition, $resource, null);

        static::assertInstanceOf(MissingValue::class, $result);
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

        static::assertSame(['posts' => 10], $result);
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

        static::assertSame(['comments' => 12], $result);
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

        static::assertSame([], $result);
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

        static::assertSame([], $result);
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
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition
     */
    private function makeFieldDefinition(
        ?string $accessor = null,
        mixed $compute = null,
        ?string $relation = null,
        ?string $resource = null,
        ?array $fields = null,
        ?\Closure $constraint = null,
        array $extras = [],
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
            guards: $guards,
            transformers: $transformers,
        );
    }
}
