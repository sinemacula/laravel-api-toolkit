<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\RestrictedArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the SoftDeleteVisibilityApplier.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SoftDeleteVisibilityApplier::class)]
final class SoftDeleteVisibilityApplierTest extends TestCase
{
    /** @var string */
    private const string TEST_URL = '/test';

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier */
    private SoftDeleteVisibilityApplier $applier;

    /**
     * Set up the applier under test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->applier = new SoftDeleteVisibilityApplier;
    }

    /**
     * Test that the None state leaves the default soft-delete scope in place.
     *
     * @return void
     */
    public function testNoneStateLeavesQueryUntouched(): void
    {
        $this->parse(null);

        $query = Article::query();

        $this->applier->apply($query, ArticleResource::class, $this->request());

        self::assertStringContainsString('deleted_at', $this->sqlOf($query));
        self::assertStringContainsString('is null', $this->sqlOf($query));
    }

    /**
     * Test that a model without SoftDeletes is never widened, even when the
     * gate would otherwise be open.
     *
     * @return void
     */
    public function testNonSoftDeleteModelLeavesQueryUntouched(): void
    {
        $this->parse('with');

        $query    = User::query();
        $expected = $this->sqlOf(User::query());

        $this->applier->apply($query, UserResource::class, $this->request());

        self::assertSame($expected, $this->sqlOf($query));
    }

    /**
     * Test that a resource that has not opted in keeps trashed rows hidden.
     *
     * @return void
     */
    public function testClosedGateResourceLeavesQueryUntouched(): void
    {
        $this->parse('with');

        $query = Article::query();

        $this->applier->apply($query, RestrictedArticleResource::class, $this->request());

        self::assertStringContainsString('is null', $this->sqlOf($query));
    }

    /**
     * Test that a non-ApiResource class string keeps the gate closed.
     *
     * @return void
     */
    public function testNonApiResourceClassLeavesQueryUntouched(): void
    {
        $this->parse('with');

        $query = Article::query();

        $this->applier->apply($query, \stdClass::class, $this->request());

        self::assertStringContainsString('is null', $this->sqlOf($query));
    }

    /**
     * Test that a null resource class keeps the gate closed.
     *
     * @return void
     */
    public function testNullResourceClassLeavesQueryUntouched(): void
    {
        $this->parse('with');

        $query = Article::query();

        $this->applier->apply($query, null, $this->request());

        self::assertStringContainsString('is null', $this->sqlOf($query));
    }

    /**
     * Test that the With state drops the soft-delete constraint so trashed rows
     * are included alongside live rows.
     *
     * @return void
     */
    public function testWithStateIncludesTrashed(): void
    {
        $this->parse('with');

        $query = Article::query();

        $this->applier->apply($query, ArticleResource::class, $this->request());

        self::assertStringNotContainsString('deleted_at', $this->sqlOf($query));
    }

    /**
     * Test that the Only state restricts the query to trashed rows.
     *
     * @return void
     */
    public function testOnlyStateReturnsOnlyTrashed(): void
    {
        $this->parse('only');

        $query = Article::query();

        $this->applier->apply($query, ArticleResource::class, $this->request());

        self::assertStringContainsString('is not null', $this->sqlOf($query));
    }

    /**
     * Parse an API query carrying the given trashed value.
     *
     * @param  string|null  $trashed
     * @return void
     */
    private function parse(?string $trashed): void
    {
        $params = $trashed === null ? [] : ['trashed' => $trashed];

        ApiQuery::parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $params));
    }

    /**
     * Build a bare request for the gate check.
     *
     * @return \Illuminate\Http\Request
     */
    private function request(): Request
    {
        return Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
    }

    /**
     * Compile the query to its SQL string for assertion.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return string
     */
    private function sqlOf(Builder $query): string
    {
        return $query->toSql(); // @phpstan-ignore staticMethod.dynamicCall
    }
}
