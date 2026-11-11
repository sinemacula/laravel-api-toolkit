<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\FilterableUserResource;
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
     * Test that only a declared filter column is permitted.
     *
     * @return void
     */
    public function testPermitsDeclaredAndRejectsUndeclaredFilter(): void
    {
        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        $this->assertPermits(fn () => $surface->guardFilter('email', new User), 'email');

        try {
            $surface->guardFilter('password', new User);
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('filters.password', $exception->errors());
            self::assertSame('The "password" key is not a permitted query parameter for this resource.', $exception->errors()['filters.password'][0]);
        }
    }

    /**
     * Test that the operator gate follows the column's capability.
     *
     * @return void
     */
    public function testGatesEachOperatorAgainstTheDeclaringColumnsCapability(): void
    {
        $surface = $this->make(filterable: ['status' => Capability::ENUM, 'email' => Capability::EXACT]);

        $this->assertPermits(fn () => $surface->guardFilterOperator('status', '$neq', new User), 'status');

        try {
            $surface->guardFilterOperator('email', '$neq', new User);
            self::fail('Expected a ValidationException for an operator the capability does not answer.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "$neq" operator is not permitted on the "email" key for this resource, which accepts $eq, $in, $null, $notNull.'],
                $exception->errors()['filters.email.$neq'] ?? [],
            );
        }
    }

    /**
     * Test that the operator gate rejects an undeclared column as undeclared.
     *
     * @return void
     */
    public function testOperatorGateRejectsAnUndeclaredColumnAsAnUndeclaredKey(): void
    {
        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        try {
            $surface->guardFilterOperator('password', '$eq', new User);
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "password" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.password'] ?? [],
            );
        }
    }

    /**
     * Test that a token outside the capability matrix is permitted.
     *
     * @return void
     */
    public function testOperatorGatePermitsATokenTheMatrixDoesNotGovern(): void
    {
        $surface = $this->make(filterable: ['email' => Capability::OPAQUE]);

        $this->assertPermits(fn () => $surface->guardFilterOperator('email', '$starts', new User), 'email');
    }

    /**
     * Test that a related column is gated by the related resource's capability.
     *
     * @return void
     */
    public function testOperatorGateReadsTheRelatedResourcesCapability(): void
    {
        $surface = $this->make(
            filterable: ['context' => Capability::DOCUMENT],
            resourceMap: [Post::class => PostResource::class],
        );

        $this->assertPermits(fn () => $surface->guardFilterOperator('title', '$eq', new Post), 'title');

        try {
            $surface->guardFilterOperator('title', '$contains', new Post);
            self::fail('Expected a ValidationException for an operator the related capability does not answer.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "$contains" operator is not permitted on the "title" key for this resource, which accepts $eq, $in, $null, $notNull.'],
                $exception->errors()['filters.title.$contains'] ?? [],
            );
        }
    }

    /**
     * Test that sort columns and traversable relations must be declared.
     *
     * @return void
     */
    public function testGovernsSortAndRelationDeclarations(): void
    {
        $surface = $this->make(sortable: ['created_at'], relations: ['posts']);

        $this->assertPermits(fn () => $surface->guardSort('created_at', new User), 'created_at');
        $this->assertPermits(fn () => $surface->guardRelation('posts', new User), 'posts');

        $this->assertRejects(fn () => $surface->guardSort('secret', new User), 'order.secret');
        $this->assertRejects(fn () => $surface->guardRelation('audits', new User), 'filters.audits');
    }

    /**
     * Test that isDeclaredRelation reports root relations without throwing.
     *
     * @return void
     */
    public function testIsDeclaredRelationReportsTheRootTraversableSetWithoutThrowing(): void
    {
        $surface = $this->make(relations: ['posts']);

        self::assertTrue($surface->isDeclaredRelation('posts', new User));
        self::assertFalse($surface->isDeclaredRelation('audits', new User));
    }

    /**
     * Test that a resource with no declared surface rejects every root key.
     *
     * @return void
     */
    public function testEmptySurfaceRejectsEveryKey(): void
    {
        $surface = $this->make();

        $this->assertRejects(fn () => $surface->guardFilter('email', new User), 'filters.email');
    }

    /**
     * Test that a column filterable in the related resource is permitted.
     *
     * @return void
     */
    public function testPermitsDeclaredFilterableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            filterable: ['email' => Capability::EXACT],
            resourceMap: [Post::class => PostResource::class],
        );

        $this->assertPermits(fn () => $surface->guardFilter('title', new Post), 'title');
    }

    /**
     * Test that a column not filterable in the related resource is rejected.
     *
     * @return void
     */
    public function testRejectsUndeclaredFilterableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            filterable: ['email' => Capability::EXACT],
            resourceMap: [Post::class => PostResource::class],
        );

        $this->assertRejects(fn () => $surface->guardFilter('secret', new Post), 'filters.secret');
    }

    /**
     * Test that a column sortable in the related resource is permitted.
     *
     * @return void
     */
    public function testPermitsDeclaredSortableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            resourceMap: [User::class => FilterableUserResource::class],
            rootModel: new Post,
        );

        $this->assertPermits(fn () => $surface->guardSort('created_at', new User), 'created_at');
    }

    /**
     * Test that a filterable but unsortable related column cannot be ordered.
     *
     * @return void
     */
    public function testRejectsUnsortableColumnOnRelatedModel(): void
    {
        $surface = $this->make(
            resourceMap: [Post::class => PostResource::class],
        );

        // PostResource declares 'title' filterable but NOT sortable.
        $this->assertRejects(fn () => $surface->guardSort('title', new Post), 'order.title');
    }

    /**
     * Test that a related model with no mapped resource rejects every column.
     *
     * @return void
     */
    public function testFailsClosedWhenRelatedModelHasNoMappedResource(): void
    {
        // Empty resource map - Post is not mapped.
        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        $this->assertRejects(fn () => $surface->guardFilter('title', new Post), 'filters.title');
    }

    /**
     * Test that a related model mapped to a non-resource class fails closed.
     *
     * @return void
     */
    public function testFailsClosedWhenMappedResourceIsNotAnApiResource(): void
    {
        $surface = $this->make(resourceMap: [Post::class => \stdClass::class]);

        $this->assertRejects(fn () => $surface->guardFilter('title', new Post), 'filters.title');
    }

    /**
     * Test that a relation traversable in the related resource is permitted.
     *
     * @return void
     */
    public function testPermitsDeclaredTraversableRelationOnRelatedModel(): void
    {
        $surface = $this->make(
            resourceMap: [User::class => FilterableUserResource::class],
            rootModel: new Post,
        );

        $this->assertPermits(fn () => $surface->guardRelation('posts', new User), 'posts');
    }

    /**
     * Test that a relation not traversable in the related resource is rejected.
     *
     * @return void
     */
    public function testRejectsUndeclaredTraversableRelationOnRelatedModel(): void
    {
        $surface = $this->make(
            resourceMap: [User::class => FilterableUserResource::class],
            rootModel: new Post,
        );

        $this->assertRejects(fn () => $surface->guardRelation('organization', new User), 'filters.organization');
    }

    /**
     * Test that a related model with no mapped resource rejects every relation.
     *
     * @return void
     */
    public function testFailsClosedWhenRelatedModelHasNoMappedResourceForRelation(): void
    {
        // Empty resource map, so User has no resolvable traversable set
        $surface = $this->make(rootModel: new Post);

        $this->assertRejects(fn () => $surface->guardRelation('posts', new User), 'filters.posts');
    }

    /**
     * Build a query surface for the test under focus.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  array<string, string>  $resourceMap
     * @param  \Illuminate\Database\Eloquent\Model|null  $rootModel
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function make(array $filterable = [], array $sortable = [], array $relations = [], array $resourceMap = [], ?Model $rootModel = null): QuerySurface
    {
        return new QuerySurface(
            $filterable,
            $sortable,
            $relations,
            $rootModel ?? new User,
            $resourceMap,
        );
    }

    /**
     * Assert that invoking the guard permits the key.
     *
     * @param  callable(): void  $guard
     * @param  string  $key
     * @return void
     */
    private function assertPermits(callable $guard, string $key): void
    {
        $rejection = null;

        try {
            $guard();
        } catch (ValidationException $exception) {
            $rejection = $exception->errors();
        }

        self::assertNull($rejection, 'The "' . $key . '" key was rejected but should have been permitted.');
    }

    /**
     * Assert that the guard rejects with a ValidationException for the key.
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
