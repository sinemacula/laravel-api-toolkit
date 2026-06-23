<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
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

        static::assertTrue($surface->guardFilter('email', new User));

        try {
            $surface->guardFilter('password', new User);
            static::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            static::assertArrayHasKey('filters.password', $exception->errors());
            static::assertStringContainsString('password', $exception->errors()['filters.password'][0]);
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

        static::assertTrue($surface->guardSort('created_at', new User));
        static::assertTrue($surface->guardRelation('posts', new User));

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

        static::assertFalse($surface->guardFilter('password', new User));
    }

    /**
     * Test that a resource with no declared surface rejects every root key under
     * the default allowlist posture (secure by default).
     *
     * @return void
     */
    public function testEmptySurfaceRejectsEveryKey(): void
    {
        $surface = $this->make();

        $this->assertRejects(fn () => $surface->guardFilter('email', new User), 'filters.email');
    }

    /**
     * Test that a key targeting a nested/related model falls back to the legacy
     * searchable predicate rather than the root allowlist, and is never rejected
     * (nested-column granularity is deferred to P2).
     *
     * @return void
     */
    public function testNestedRelatedModelFallsBackToLegacySearchable(): void
    {
        $introspector = \Mockery::mock(SchemaIntrospectionProvider::class);
        $introspector->shouldReceive('isSearchable')->with(\Mockery::type(Post::class), 'title')->andReturnTrue();
        $introspector->shouldReceive('isSearchable')->with(\Mockery::type(Post::class), 'secret')->andReturnFalse();

        $surface = $this->make(filterable: ['email'], introspector: $introspector);

        static::assertTrue($surface->guardFilter('title', new Post));
        static::assertFalse($surface->guardFilter('secret', new Post));
    }

    /**
     * Test that the blocklist posture delegates to the schema introspector and
     * drops (rather than rejects) an unsearchable key.
     *
     * @return void
     */
    public function testBlocklistDelegatesToIntrospectorAndDropsUnsearchable(): void
    {
        $introspector = \Mockery::mock(SchemaIntrospectionProvider::class);
        $introspector->shouldReceive('isSearchable')->with(\Mockery::any(), 'email')->andReturnTrue();
        $introspector->shouldReceive('isSearchable')->with(\Mockery::any(), 'secret')->andReturnFalse();
        $introspector->shouldReceive('isRelation')->with('posts', \Mockery::any())->andReturnTrue();

        $surface = $this->make(posture: QuerySurface::POSTURE_BLOCKLIST, introspector: $introspector);

        static::assertTrue($surface->guardFilter('email', new User));
        static::assertTrue($surface->guardRelation('posts', new User));
        static::assertFalse($surface->guardFilter('secret', new User));
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
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function make(
        array $filterable = [],
        array $sortable = [],
        array $relations = [],
        string $posture = QuerySurface::POSTURE_ALLOWLIST,
        bool $reject = true,
        ?SchemaIntrospectionProvider $introspector = null,
    ): QuerySurface {
        return new QuerySurface(
            $filterable,
            $sortable,
            $relations,
            $posture,
            $reject,
            $introspector ?? \Mockery::mock(SchemaIntrospectionProvider::class),
            new User,
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
            static::fail('Expected a ValidationException for key ' . $errorKey . '.');
        } catch (ValidationException $exception) {
            static::assertArrayHasKey($errorKey, $exception->errors());
        }
    }
}
