<?php

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
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
class QuerySurfaceTest extends TestCase
{
    /**
     * Test that the allowlist posture permits a declared filter column and
     * rejects an undeclared one with a validation error naming the key.
     *
     * @return void
     */
    public function testAllowlistPermitsDeclaredAndRejectsUndeclaredFilter(): void
    {
        $surface = $this->make(filterable: ['email']);

        static::assertTrue($surface->guardFilter('email'));

        try {
            $surface->guardFilter('password');
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

        static::assertTrue($surface->guardSort('created_at'));
        static::assertTrue($surface->guardRelation('posts'));

        $this->assertRejects(fn () => $surface->guardSort('secret'), 'order.secret');
        $this->assertRejects(fn () => $surface->guardRelation('audits'), 'filters.audits');
    }

    /**
     * Test that the fail-quiet toggle drops an undeclared key without throwing.
     *
     * @return void
     */
    public function testFailQuietDropsUndeclaredKeyWithoutThrowing(): void
    {
        $surface = $this->make(filterable: ['email'], reject: false);

        static::assertFalse($surface->guardFilter('password'));
    }

    /**
     * Test that a resource with no declared surface rejects every key under the
     * default allowlist posture (secure by default).
     *
     * @return void
     */
    public function testEmptySurfaceRejectsEveryKey(): void
    {
        $surface = $this->make();

        $this->assertRejects(fn () => $surface->guardFilter('email'), 'filters.email');
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

        static::assertTrue($surface->guardFilter('email'));
        static::assertTrue($surface->guardRelation('posts'));
        static::assertFalse($surface->guardFilter('secret'));
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
            \Mockery::mock(Model::class),
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
