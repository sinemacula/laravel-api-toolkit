<?php

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ColumnProjectionApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjectionApplier::class)]
final class ColumnProjectionApplierTest extends TestCase
{
    /** @var array<int, string> */
    private const array USER_DEFAULT_FIELDS = ['id', 'name', 'email'];

    /** @var array<int, string> */
    private const array USER_COLUMNS = ['id', 'organization_id', 'name', 'email', 'password', 'status', 'created_at', 'updated_at'];

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that apply returns the query unchanged when the narrow flag is off.
     *
     * @return void
     */
    public function testFlagOffReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(static::never())->method('resolveFields');
        $provider->expects(static::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        static::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that apply returns the query unchanged for a null resource class.
     *
     * @return void
     */
    public function testNullResourceClassReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(static::never())->method('resolveFields');
        $provider->expects(static::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, null, []);

        static::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that apply returns the query unchanged for a non-ApiResource class.
     *
     * @return void
     */
    public function testNonApiResourceClassReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = $this->createMock(ResourceMetadataProvider::class);
        $provider->expects(static::never())->method('resolveFields');
        $provider->expects(static::never())->method('getAllFields');

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, \stdClass::class, []);

        static::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that apply returns the query unchanged when the resolved field set is
     * empty.
     *
     * @return void
     */
    public function testEmptyResolvedFieldsReturnsQueryWithoutSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = static::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        static::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that a fall-back decision applies no select and leaves the query
     * unchanged.
     *
     * @return void
     */
    public function testFallbackDecisionAppliesNoSelect(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = static::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(['id', 'full_label']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        static::assertNull($result->getQuery()->columns);
    }

    /**
     * Test that a narrow decision applies the select exactly once with the
     * decision columns.
     *
     * @return void
     */
    public function testNarrowDecisionAppliesSelectOnce(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = static::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, ['name' => 'asc']);

        static::assertSame(['id', 'name', 'email'], $result->getQuery()->columns);
    }

    /**
     * Test that the ':all' fields token resolves the full declared field set via
     * getAllFields rather than the request-narrowed resolveFields.
     *
     * @return void
     */
    public function testAllFieldsTokenResolvesViaGetAllFields(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request(['fields' => ['users' => ':all']]));

        $provider = static::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('getAllFields')->willReturn(['id', 'name']);
        $provider->method('resolveFields')->willReturn(['id', 'email']);
        $provider->method('eagerLoadMapFor')->willReturn([]);

        $query  = (new User)->newQuery();
        $result = $this->makeApplier()->apply($query, $provider, UserResource::class, []);

        static::assertSame(['id', 'name'], $result->getQuery()->columns);
    }

    /**
     * Test that the parent key of every list-keyed (plain or extra) relation in
     * the eager-load map is retained, not just the first. The relation name of a
     * list entry lives in the value, so deriving keys via array_keys would yield
     * integer indices that resolve to no relation.
     *
     * @return void
     */
    public function testRetainsParentKeysForEveryListKeyedRelation(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = static::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn(['author', 'editor']);

        $author = static::createStub(Relation::class);
        $editor = static::createStub(Relation::class);

        $introspector = static::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn([...self::USER_COLUMNS, 'author_id', 'editor_id']);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturnCallback(
            static fn (string $key): ?Relation => match ($key) {
                'author' => $author,
                'editor' => $editor,
                default  => null,
            },
        );
        $introspector->method('parentKeysFor')->willReturnCallback(
            static fn (Relation $relation): array => $relation === $author ? ['author_id'] : ['editor_id'],
        );

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector));

        $columns = $applier->apply((new User)->newQuery(), $provider, UserResource::class, [])->getQuery()->columns;

        static::assertNotNull($columns);
        static::assertContains('author_id', $columns);
        static::assertContains('editor_id', $columns);
    }

    /**
     * Test that a dotted relation path is reduced to its first segment - the
     * relation declared on the base model - so the base-model parent key is
     * resolved rather than the whole path, which matches no relation.
     *
     * @return void
     */
    public function testStripsDottedPathToBaseRelationForParentKey(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $this->parseRequest(new Request);

        $provider = static::createStub(ResourceMetadataProvider::class);
        $provider->method('getResourceType')->willReturn('users');
        $provider->method('resolveFields')->willReturn(self::USER_DEFAULT_FIELDS);
        $provider->method('eagerLoadMapFor')->willReturn(['posts.comments']);

        $relation = static::createStub(Relation::class);

        $introspector = static::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn(self::USER_COLUMNS);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturnCallback(
            static fn (string $key): ?Relation => $key === 'posts' ? $relation : null,
        );
        $introspector->method('parentKeysFor')->willReturn(['organization_id']);

        $applier = new ColumnProjectionApplier(new SafetySetDeriver($introspector));

        $columns = $applier->apply((new User)->newQuery(), $provider, UserResource::class, [])->getQuery()->columns;

        static::assertNotNull($columns);
        static::assertContains('organization_id', $columns);
    }

    /**
     * Build a column projection applier over a stubbed introspection provider
     * reporting the fixture user columns.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier
     */
    private function makeApplier(): ColumnProjectionApplier
    {
        $introspector = static::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturn(self::USER_COLUMNS);
        $introspector->method('getDeletedAtColumn')->willReturn(null);
        $introspector->method('resolveRelation')->willReturn(null);

        return new ColumnProjectionApplier(new SafetySetDeriver($introspector));
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function parseRequest(Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
