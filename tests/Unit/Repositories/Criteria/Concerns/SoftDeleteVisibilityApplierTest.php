<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\HeaderGatedArticleResource;
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
     * Provide every unhonourable root under each trashed state it refuses.
     *
     * @return iterable<string, array{class-string<\Illuminate\Database\Eloquent\Model>, string|null, string}>
     */
    public static function unhonourableProvider(): iterable
    {
        foreach (['with', 'only'] as $trashed) {
            foreach (self::unhonourableRootProvider() as $name => [$model, $resourceClass]) {
                yield "{$trashed} on {$name}" => [$model, $resourceClass, $trashed];
            }
        }
    }

    /**
     * Test that a trashed request the root cannot honour is refused.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  string|null  $resourceClass
     * @param  string  $trashed
     * @return void
     */
    #[DataProvider('unhonourableProvider')]
    public function testTrashedTheRootCannotHonourIsRefused(string $model, ?string $resourceClass, string $trashed): void
    {
        $this->parse($trashed);

        try {
            $this->applier->apply($model::query(), $resourceClass, $this->request());
        } catch (ValidationException $exception) {
            self::assertSame(['trashed' => ['The trashed parameter is not permitted for this resource.']], $exception->errors());

            return;
        }

        self::fail('A trashed request the root cannot honour was not refused.');
    }

    /**
     * Provide the roots that cannot honour a trashed request.
     *
     * @return iterable<string, array{class-string<\Illuminate\Database\Eloquent\Model>, string|null}>
     */
    public static function unhonourableRootProvider(): iterable
    {
        yield 'a model without soft deletes' => [User::class, UserResource::class];
        yield 'a resource that has not opted in' => [Article::class, RestrictedArticleResource::class];
        yield 'a class that is not an api resource' => [Article::class, \stdClass::class];
        yield 'no resource' => [Article::class, null];
    }

    /**
     * Test that the None state leaves every unhonourable root untouched.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  string|null  $resourceClass
     * @return void
     */
    #[DataProvider('unhonourableRootProvider')]
    public function testNoneStateLeavesAnUnhonourableRootUntouched(string $model, ?string $resourceClass): void
    {
        $this->parse(null);

        $query    = $model::query();
        $expected = $this->sqlOf($model::query());

        self::assertSame($query, $this->applier->apply($query, $resourceClass, $this->request()));
        self::assertSame($expected, $this->sqlOf($query));
    }

    /**
     * Test that the With state includes trashed rows alongside live ones.
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
     * Test that a carried trashed value is refused under a gate closed to the
     * request it replays in, rather than answered with live rows.
     *
     * @return void
     */
    public function testCarriedTrashedIsRefusedUnderAClosedGate(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The trashed parameter is not permitted for this resource.');

        ApiQuery::using(
            new ApiQuerySnapshot(['trashed' => 'only']),
            fn (): mixed => $this->applier->apply(Article::query(), HeaderGatedArticleResource::class, $this->request()),
        );
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return string
     */
    private function sqlOf(Builder $query): string
    {
        return $query->toSql(); // @phpstan-ignore staticMethod.dynamicCall
    }
}
