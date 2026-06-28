<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Tests for the QuerySurface enforcement policy.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
final class QuerySurfaceTest extends TestCase
{
    /**
     * Test that the allowlist posture permits a declared root filter column and
     * rejects an undeclared one with a validation error naming the key.
     *
     * @return void
     */
    public function testAllowlistPermitsDeclaredAndRejectsUndeclaredFilter(): void
    {
        $surface = $this->make(filterable: ['email']);

        self::assertTrue($surface->guardFilter('email', new User));

        try {
            $surface->guardFilter('password', new User);
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('filters.password', $exception->errors());
            self::assertStringContainsString('password', $exception->errors()['filters.password'][0]);
        }
    }

    /**
     * Test that declared sort columns and traversable relations are permitted
     * and undeclared ones rejected under the allowlist posture.
     *
     * @return void
     */
    public function testAllowlistGovernsSortAndRelationCapabilities(): void
    {
        $surface = $this->make(sortable: ['created_at'], relations: ['posts']);

        self::assertTrue($surface->guardSort('created_at', new User));
        self::assertTrue($surface->guardRelation('posts', new User));

        $this->assertRejects(fn () => $surface->guardSort('secret', new User), 'order.secret');
        $this->assertRejects(fn () => $surface->guardRelation('audits', new User), 'filters.audits');
    }

    /**
     * Test that the fail-quiet toggle drops an undeclared key without throwing.
     *
     * @return void
     */
    public function testFailQuietDropsUndeclaredKeyWithoutThrowing(): void
    {
        $surface = $this->make(filterable: ['email'], reject: false);

        self::assertFalse($surface->guardFilter('password', new User));
    }

    /**
     * Test that a resource with no declared surface rejects every root key
     * under the default allowlist posture (secure by default).
     *
     * @return void
     */
    public function testEmptySurfaceRejectsEveryKey(): void
    {
        $surface = $this->make();

        $this->assertRejects(fn () => $surface->guardFilter('email', new User), 'filters.email');
    }

    /**
     * Test that under the allowlist posture, a column declared filterable in
     * the related model's mapped resource is permitted.
     *
     * PostResource declares 'title' as filterable, so it must pass.
     *
     * @return void
     */
    public function testAllowlistPermitsDeclaredFilterableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            filterable: ['email'],
            resourceMap: [Post::class => PostResource::class],
        );

        self::assertTrue($surface->guardFilter('title', new Post));
    }

    /**
     * Test that under the allowlist posture, a column NOT declared in the
     * related model's resource filterable set is rejected with a
     * ValidationException.
     *
     * @return void
     */
    public function testAllowlistRejectsUndeclaredFilterableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            filterable: ['email'],
            resourceMap: [Post::class => PostResource::class],
        );

        $this->assertRejects(fn () => $surface->guardFilter('secret', new Post), 'filters.secret');
    }

    /**
     * Test that under the allowlist posture, a column that is filterable but
     * not sortable in the related resource is rejected for ordering, confirming
     * the two sets are enforced independently.
     *
     * @return void
     */
    public function testAllowlistRejectsUnsortableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            resourceMap: [Post::class => PostResource::class],
        );

        // PostResource declares 'title' filterable but NOT sortable.
        $this->assertRejects(fn () => $surface->guardSort('title', new Post), 'order.title');
    }

    /**
     * Test that under the allowlist posture a related model with no entry in
     * the resource map fails closed: every column is rejected.
     *
     * @return void
     */
    public function testAllowlistFailsClosedWhenRelatedModelHasNoMappedResource(): void
    {
        // Empty resource map - Post is not mapped.
        $surface = $this->make(filterable: ['email']);

        $this->assertRejects(fn () => $surface->guardFilter('title', new Post), 'filters.title');
    }

    /**
     * Test that fail-quiet silently drops an undeclared related-model column
     * without throwing, even under the allowlist posture.
     *
     * @return void
     */
    public function testFailQuietDropsUndeclaredRelatedColumnWithoutThrowing(): void
    {
        $surface = $this->make(
            filterable: ['email'],
            reject: false,
            resourceMap: [Post::class => PostResource::class],
        );

        self::assertFalse($surface->guardFilter('secret', new Post));
    }

    /**
     * Test that the blocklist posture delegates to the schema introspector for
     * related models and drops (rather than rejects) an unsearchable key.
     *
     * @return void
     */
    public function testBlocklistDelegatesToIntrospectorAndDropsUnsearchable(): void
    {
        $introspector = \Mockery::mock(SchemaIntrospectionProvider::class);
        $introspector->shouldReceive('isSearchable')->with(\Mockery::any(), 'email')->andReturnTrue(); // @phpstan-ignore method.notFound
        $introspector->shouldReceive('isSearchable')->with(\Mockery::any(), 'secret')->andReturnFalse(); // @phpstan-ignore method.notFound
        $introspector->shouldReceive('isRelation')->with('posts', \Mockery::any())->andReturnTrue(); // @phpstan-ignore method.notFound

        $surface = $this->make(posture: QuerySurface::POSTURE_BLOCKLIST, introspector: $introspector);

        self::assertTrue($surface->guardFilter('email', new User));
        self::assertTrue($surface->guardRelation('posts', new User));
        self::assertFalse($surface->guardFilter('secret', new User));
    }

    /**
     * Test that the blocklist posture still delegates related-model column
     * checks to isSearchable, not to the resource schema.
     *
     * @return void
     */
    public function testBlocklistUsesIsSearchableForRelatedModelColumns(): void
    {
        $introspector = \Mockery::mock(SchemaIntrospectionProvider::class);
        $introspector->shouldReceive('isSearchable')->with(\Mockery::type(Post::class), 'title')->andReturnTrue(); // @phpstan-ignore method.notFound
        $introspector->shouldReceive('isSearchable')->with(\Mockery::type(Post::class), 'secret')->andReturnFalse(); // @phpstan-ignore method.notFound

        // Blocklist posture: must use isSearchable, not the resource schema.
        $surface = $this->make(
            posture: QuerySurface::POSTURE_BLOCKLIST,
            introspector: $introspector,
            resourceMap: [Post::class => PostResource::class],
        );

        self::assertTrue($surface->guardFilter('title', new Post));
        self::assertFalse($surface->guardFilter('secret', new Post));
    }

    /**
     * Build a query surface with sensible defaults for the test under focus.
     *
     * @param  array<int, string>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  string  $posture
     * @param  bool  $reject
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider|null  $introspector
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function make(
        array $filterable = [],
        array $sortable = [],
        array $relations = [],
        string $posture = QuerySurface::POSTURE_ALLOWLIST,
        bool $reject = true,
        ?SchemaIntrospectionProvider $introspector = null,
        array $resourceMap = [],
    ): QuerySurface {
        return new QuerySurface(
            $filterable,
            $sortable,
            $relations,
            $posture,
            $reject,
            $introspector ?? \Mockery::mock(SchemaIntrospectionProvider::class),
            new User,
            $resourceMap,
        );
    }

    /**
     * Assert that invoking the guard rejects with a ValidationException whose
     * errors contain the given key.
     *
     * @param  callable(): void  $guard
     * @param  string  $errorKey
     * @return void
     */
    private function assertRejects(callable $guard, string $errorKey): void
    {
        try {
            $guard();
            self::fail('Expected a ValidationException for key ' . $errorKey . '.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($errorKey, $exception->errors());
        }
    }
}
