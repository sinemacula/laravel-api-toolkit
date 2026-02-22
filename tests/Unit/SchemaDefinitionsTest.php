<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class SchemaDefinitionsTest extends TestCase
{
    public function testFieldDefinitionBuildsScalarAccessorComputeAndMergedSet(): void
    {
        $scalar = Field::scalar('name', 'display_name')
            ->guard(static fn () => true)
            ->transform(static fn (ApiResource $resource, mixed $value) => strtoupper((string) $value))
            ->extras('profile');

        $definition = $scalar->toArray()['display_name'];

        static::assertArrayHasKey('guards', $definition);
        static::assertArrayHasKey('transformers', $definition);
        static::assertSame(['profile'], $definition['extras']);

        $timestamp = Field::timestamp('created_at')->toArray()['created_at']['accessor'];
        $date      = Field::date('created_at')->toArray()['created_at']['accessor'];

        $resource = new class ((object) ['created_at' => CarbonImmutable::parse('2025-01-01 10:00:00')]) extends ApiResource {
            public const string RESOURCE_TYPE = 'tmp';
            protected static array $default   = ['created_at'];

            public static function schema(): array
            {
                return ['created_at' => []];
            }
        };

        static::assertSame('2025-01-01T10:00:00+00:00', $timestamp($resource));
        static::assertSame('2025-01-01', $date($resource));

        $computed = Field::compute('calc', static fn () => 'ok')->toArray();

        static::assertArrayHasKey('compute', $computed['calc']);

        $aliased = Field::scalar('email')->alias('contact')->toArray();
        static::assertArrayHasKey('contact', $aliased);

        $merged = Field::set(
            ['first' => ['a' => 1]],
            Field::scalar('second')->toArray(),
            ['first' => ['a' => 2]],
        );

        static::assertSame(['a' => 2], $merged['first']);
        static::assertArrayHasKey('second', $merged);
    }

    public function testRelationDefinitionSupportsAccessorResourceFieldsConstraintAndAlias(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class)
            ->fields(['id', 'name', 'name'])
            ->extras('organization.address')
            ->constrain(static fn ($query) => $query)
            ->alias('org');

        $compiled = $relation->toArray()['org'];

        static::assertSame('organization', $compiled['relation']);
        static::assertSame(OrganizationResource::class, $compiled['resource']);
        static::assertSame(['id', 'name'], $compiled['fields']);
        static::assertArrayHasKey('constraint', $compiled);
        static::assertSame(['organization.address'], $compiled['extras']);

        $accessorRelation = Relation::to('organization', 'name', 'organization_name')->toArray()['organization_name'];

        static::assertSame('name', $accessorRelation['accessor']);
        static::assertArrayNotHasKey('resource', $accessorRelation);
    }

    public function testCountDefinitionSupportsDefaultAliasConstraintAndBaseDefinitionHooks(): void
    {
        $count = Count::of('posts')
            ->as('posts_total')
            ->default()
            ->guard(static fn () => true)
            ->transform(static fn (ApiResource $resource, mixed $value) => $value)
            ->extras('posts')
            ->constrain(static fn ($query) => $query);

        $compiled = $count->toArray();

        static::assertArrayHasKey('__count__:posts_total', $compiled);

        $definition = $compiled['__count__:posts_total'];

        static::assertSame('posts_total', $definition['key']);
        static::assertSame('count', $definition['metric']);
        static::assertSame('posts', $definition['relation']);
        static::assertTrue($definition['default']);
        static::assertArrayHasKey('constraint', $definition);
        static::assertArrayHasKey('guards', $definition);
        static::assertArrayHasKey('transformers', $definition);
        static::assertSame(['posts'], $definition['extras']);
    }

    public function testFixtureResourceSchemaContainsExpectedMetricAndRelationShapes(): void
    {
        $schema = UserResource::schema();

        static::assertArrayHasKey('organization', $schema);
        static::assertArrayHasKey('__count__:posts', $schema);
        static::assertSame('count', $schema['__count__:posts']['metric']);
    }
}
