<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\Fixtures\Resources\UserResource;
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
    use LocalisesToolkitMessages;

    /**
     * Test that only a declared filter column is permitted.
     *
     * @return void
     */
    public function testPermitsDeclaredAndRejectsUndeclaredFilter(): void
    {
        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        $this->assertPermits(fn () => $surface->guardFilter('email'), 'email');

        try {
            $surface->guardFilter('password');
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

        $this->assertPermits(fn () => $surface->guardFilterOperator('status', '$neq'), 'status');

        try {
            $surface->guardFilterOperator('email', '$neq');
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
            $surface->guardFilterOperator('password', '$eq');
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

        $this->assertPermits(fn () => $surface->guardFilterOperator('email', '$starts'), 'email');
    }

    /**
     * Test that every rejection is worded in the current locale.
     *
     * @return void
     */
    public function testRejectionsAreWordedInTheCurrentLocale(): void
    {
        $this->localiseToolkitMessages([
            'query.undeclared_key'         => 'Der Schlüssel ":key" ist nicht zulässig.',
            'filters.unpermitted_operator' => 'Der Operator ":operator" ist für ":key" nicht zulässig, erlaubt sind :accepts.',
        ]);

        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        $this->assertRejectsWith(fn () => $surface->guardSort('secret'), ['order.secret' => ['Der Schlüssel "secret" ist nicht zulässig.']]);
        $this->assertRejectsWith(fn () => $surface->guardFilterOperator('password', '$eq'), ['filters.password' => ['Der Schlüssel "password" ist nicht zulässig.']]);
        $this->assertRejectsWith(
            fn () => $surface->guardFilterOperator('email', '$neq'),
            ['filters.email.$neq' => ['Der Operator "$neq" ist für "email" nicht zulässig, erlaubt sind $eq, $in, $null, $notNull.']],
        );
    }

    /**
     * Test that sort columns and traversable relations must be declared.
     *
     * @return void
     */
    public function testGovernsSortAndRelationDeclarations(): void
    {
        $surface = $this->make(sortable: ['created_at'], relations: ['posts']);

        $this->assertPermits(fn () => $surface->guardSort('created_at'), 'created_at');
        $this->assertPermits(fn () => $surface->guardRelation('posts'), 'posts');

        $this->assertRejects(fn () => $surface->guardSort('secret'), 'order.secret');
        $this->assertRejects(fn () => $surface->guardRelation('audits'), 'filters.audits');
    }

    /**
     * Test that isDeclaredRelation reports the traversable set without
     * throwing.
     *
     * @return void
     */
    public function testIsDeclaredRelationReportsTheTraversableSetWithoutThrowing(): void
    {
        $surface = $this->make(relations: ['posts']);

        self::assertTrue($surface->isDeclaredRelation('posts'));
        self::assertFalse($surface->isDeclaredRelation('audits'));
    }

    /**
     * Test that a surface no resource governs rejects every key.
     *
     * @return void
     */
    public function testUngovernedSurfaceRejectsEveryKey(): void
    {
        $surface = new QuerySurface(null, $this->traversal([Post::class => PostResource::class]));

        $this->assertRejects(fn () => $surface->guardFilter('title'), 'filters.title');
        $this->assertRejects(fn () => $surface->guardFilterOperator('title', '$eq'), 'filters.title');
        $this->assertRejects(fn () => $surface->guardSort('title'), 'order.title');
        $this->assertRejects(fn () => $surface->guardRelation('user'), 'filters.user');
        $this->assertRejects(fn () => $surface->through('user', new User, false)->guardFilter('name'), 'filters.name');
    }

    /**
     * Test that a relation is governed by the resource declared over it, not
     * the related model's mapped resource.
     *
     * @return void
     */
    public function testThroughPrefersTheDeclaredResourceOverTheMappedOne(): void
    {
        $surface = $this->make(relations: ['author'], resources: ['author' => PublicUserResource::class], resourceMap: [User::class => UserResource::class]);

        $author = $surface->through('author', new User, false);

        $this->assertPermits(fn () => $author->guardFilter('name'), 'name');
        $this->assertPermits(fn () => $author->guardSort('name'), 'name');
        $this->assertRejects(fn () => $author->guardFilter('email'), 'filters.email');
        $this->assertRejects(fn () => $author->guardSort('email'), 'order.email');
    }

    /**
     * Test that a relation on the root's own model is governed by its declared
     * resource rather than the root's.
     *
     * @return void
     */
    public function testSelfRelationIsGovernedByItsDeclaredResource(): void
    {
        $surface = $this->make(
            filterable : ['email' => Capability::EXACT],
            relations  : ['manager'],
            resources  : ['manager' => PublicUserResource::class],
            resourceMap: [User::class => UserResource::class],
        );

        $manager = $surface->through('manager', new User, false);

        $this->assertPermits(fn () => $surface->guardFilter('email'), 'email');
        $this->assertRejects(fn () => $manager->guardFilter('email'), 'filters.email');
        $this->assertPermits(fn () => $manager->guardFilter('name'), 'name');
    }

    /**
     * Test that a relation declaring no resource is governed by the related
     * model's mapped resource.
     *
     * @return void
     */
    public function testThroughFallsBackToTheMappedResourceForAnAccessorOnlyRelation(): void
    {
        $surface = $this->make(relations: ['posts'], resourceMap: [Post::class => PostResource::class]);

        $posts = $surface->through('posts', new Post, false);

        $this->assertPermits(fn () => $posts->guardFilterOperator('title', '$eq'), 'title');
        $this->assertRejects(fn () => $posts->guardFilterOperator('title', '$contains'), 'filters.title.$contains');
        $this->assertRejects(fn () => $posts->guardSort('title'), 'order.title');
    }

    /**
     * Test that a polymorphic descent is governed by the concrete type's mapped
     * resource, whatever resource the relation declares.
     *
     * @return void
     */
    public function testThroughResolvesAPolymorphicDescentPerType(): void
    {
        $surface = $this->make(
            relations  : ['noteable'],
            resources  : ['noteable' => PublicUserResource::class],
            resourceMap: [User::class => FilterableUserResource::class, Post::class => PostResource::class],
        );

        $this->assertPermits(fn () => $surface->through('noteable', new User, true)->guardFilter('email'), 'email');
        $this->assertPermits(fn () => $surface->through('noteable', new Post, true)->guardFilter('title'), 'title');
        $this->assertRejects(fn () => $surface->through('noteable', new Post, true)->guardFilter('email'), 'filters.email');
    }

    /**
     * Test that a level no resource governs refuses every key beneath it.
     *
     * @return void
     */
    public function testThroughFailsClosedWhenNoResourceGovernsTheLevel(): void
    {
        $surface = $this->make(
            relations  : ['posts', 'user', 'tags'],
            resources  : ['tags' => \stdClass::class],
            resourceMap: [User::class => \stdClass::class],
        );

        $this->assertRejects(fn () => $surface->through('posts', new Post, false)->guardFilter('title'), 'filters.title');
        $this->assertRejects(fn () => $surface->through('user', new User, false)->guardFilter('name'), 'filters.name');
        $this->assertRejects(fn () => $surface->through('tags', new Post, false)->guardRelation('user'), 'filters.user');
    }

    /**
     * Build a query surface over the given declarations.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  array<string, string>  $resources
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function make(array $filterable = [], array $sortable = [], array $relations = [], array $resources = [], array $resourceMap = []): QuerySurface
    {
        return new QuerySurface(new CompiledSchema([], [], $filterable, $sortable, $relations, [], $resources), $this->traversal($resourceMap));
    }

    /**
     * Build the relation traversal over the given resource map.
     *
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Schema\RelationTraversal
     */
    private function traversal(array $resourceMap): RelationTraversal
    {
        return new RelationTraversal(app(SchemaIntrospectionProvider::class), $resourceMap);
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
     * Assert that the guard rejects with exactly the given errors.
     *
     * @param  callable(): void  $guard
     * @param  array<string, array<int, string>>  $errors
     * @return void
     */
    private function assertRejectsWith(callable $guard, array $errors): void
    {
        try {
            $guard();
            self::fail('Expected a ValidationException for ' . implode(', ', array_keys($errors)) . '.');
        } catch (ValidationException $exception) {
            self::assertSame($errors, $exception->errors());
        }
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
