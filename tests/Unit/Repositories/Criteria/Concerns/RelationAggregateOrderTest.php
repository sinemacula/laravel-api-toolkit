<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationAggregateOrder;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Repositories\Criteria\SortTarget;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\AuthoredPost;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for ordering by a relation aggregate.
 *
 * Each order is held to the subselect Laravel's own aggregate loaders build, so
 * the order reads exactly the value the payload renders.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationAggregateOrder::class)]
final class RelationAggregateOrderTest extends TestCase
{
    /**
     * Test that a count over a self relation orders by the subselect
     * withCount() builds.
     *
     * @return void
     */
    public function testSelfRelationCountMatchesWithCount(): void
    {
        $this->assertOrdersLike(Article::query(), $this->aggregate('siblingArticles', AggregateFunction::COUNT), Article::query()->withCount('siblingArticles'));
    }

    /**
     * Test that a count over a pivot relation orders by the subselect
     * withCount() builds.
     *
     * @return void
     */
    public function testPivotCountMatchesWithCount(): void
    {
        $this->assertOrdersLike(Post::query(), $this->aggregate('tags', AggregateFunction::COUNT), Post::query()->withCount('tags'));
    }

    /**
     * Test that a sum and an average order by the subselects withSum() and
     * withAvg() build.
     *
     * @return void
     */
    public function testSumAndAverageMatchWithSumAndWithAvg(): void
    {
        $this->assertOrdersLike(User::query(), $this->aggregate('articles', AggregateFunction::SUM, 'views'), User::query()->withSum('articles', 'views'));
        $this->assertOrdersLike(User::query(), $this->aggregate('articles', AggregateFunction::AVG, 'views'), User::query()->withAvg('articles', 'views'));
    }

    /**
     * Test that an existence check and a minimum order by the expressions
     * withExists() and withMin() build.
     *
     * @return void
     */
    public function testExistsAndMinMatchWithExistsAndWithMin(): void
    {
        $this->assertOrdersLike(User::query(), $this->aggregate('posts', AggregateFunction::EXISTS), User::query()->withExists('posts'));
        $this->assertOrdersLike(User::query(), $this->aggregate('articles', AggregateFunction::MIN, 'views'), User::query()->withMin('articles', 'views'));
    }

    /**
     * Test that a one-of-many relation orders by the stored column of the row
     * it renders, as a column subselect over that row.
     *
     * @return void
     */
    public function testOneOfManyRelationOrdersByTheStoredColumnOfItsRow(): void
    {
        $reference = User::query()->withAggregate('latestArticle', new Expression('"articles"."views"'));

        $this->assertOrdersLike(User::query(), SortTarget::related('latestArticle', 'views'), $reference);
    }

    /**
     * Test that a belongs-to relation reads the related column qualified by its
     * table, and a relation to the model's own table reads it through the
     * subquery's alias.
     *
     * @return void
     */
    public function testToOneColumnIsQualifiedUnlessTheRelationReachesItsOwnTable(): void
    {
        $model = new #[Table('users')] class extends Model {
            /**
             * Get the parent user.
             *
             * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function parent(): BelongsTo
            {
                return $this->belongsTo(self::class, 'organization_id');
            }
        };

        $post = Post::query();
        $self = $model->newQuery();

        $this->order()->apply($post, SortTarget::related('user', 'name'), 'asc', UserResource::class);
        $this->order()->apply($self, SortTarget::related('parent', 'name'), 'asc', UserResource::class);

        self::assertSame('(select "users"."name" from "users" where "posts"."user_id" = "users"."id" limit 1)', $this->orderSql($post));
        self::assertSame(
            '(select "name" from "users" as "laravel_reserved_0" where "laravel_reserved_0"."id" = "users"."organization_id" limit 1)',
            $this->orderSql($self),
        );
    }

    /**
     * Test that a constraint carrying a binding and one adding a join order by
     * the subselects withCount() builds, bindings included.
     *
     * @return void
     */
    public function testConstraintsWithBindingsAndJoinsMatchWithCount(): void
    {
        $bound = static function (Builder $query): void {
            $query->where('published', true);
        };

        $joined = static function (Builder $query): void {
            $query->join('users as authors', 'authors.id', '=', 'posts.user_id')->where('authors.name', 'Ada');
        };

        $this->assertOrdersLike(User::query(), $this->aggregate('posts', AggregateFunction::COUNT, null, $bound), User::query()->withCount(['posts' => $bound]));
        $this->assertOrdersLike(User::query(), $this->aggregate('posts', AggregateFunction::COUNT, null, $joined), User::query()->withCount(['posts' => $joined]));
    }

    /**
     * Test that a model's default aggregates leave the order's bindings to the
     * order alone.
     *
     * @return void
     */
    public function testDefaultModelAggregatesAddNoOrderBindings(): void
    {
        $model = new #[Table('users')] class extends Model {
            /** @var array<int, string> */
            protected $withCount = ['publishedPosts']; // @phpstan-ignore property.phpDocType

            /**
             * Get the posts.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             */
            public function posts(): HasMany
            {
                return $this->hasMany(Post::class, 'user_id');
            }

            /**
             * Get the published posts.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             */
            public function publishedPosts(): HasMany
            {
                return $this->hasMany(Post::class, 'user_id')->where('published', true); // @phpstan-ignore return.type
            }
        };

        $query = $model->newQuery();

        $this->order()->apply($query, $this->aggregate('posts', AggregateFunction::COUNT), 'asc', UserResource::class);

        self::assertSame([true], $query->getQuery()->bindings['select']);
        self::assertSame([], $query->getQuery()->bindings['order']);
    }

    /**
     * Test that the trashed state the request asks for widens the related rows
     * the order counts, as it widens those the payload counts.
     *
     * @return void
     */
    public function testTrashedStateWidensTheRowsTheOrderCounts(): void
    {
        $live = Article::query();

        $this->order()->apply($live, $this->aggregate('siblingArticles', AggregateFunction::COUNT), 'asc', ArticleResource::class);

        ApiQuery::parse(Request::create('/', HttpMethod::GET->getVerb(), ['trashed' => 'with']));

        $widened = Article::query();

        $this->order()->apply($widened, $this->aggregate('siblingArticles', AggregateFunction::COUNT), 'asc', ArticleResource::class);

        self::assertStringContainsString('"laravel_reserved_0"."deleted_at" is null', $this->orderSql($live));
        self::assertStringNotContainsString('deleted_at', $this->orderSql($widened));
    }

    /**
     * Test that a plain has-one relation, which renders no particular row, is
     * refused as a schema defect.
     *
     * @return void
     */
    public function testPlainHasOneIsRefusedAsASchemaDefect(): void
    {
        try {
            $this->order()->apply(User::query(), SortTarget::related('profile', 'bio'), 'asc', UserResource::class);
            self::fail('Expected an InvalidSchemaException for a plain has-one relation.');
        } catch (InvalidSchemaException $exception) {
            self::assertSame(
                sprintf(
                    '[%s] Field "profile": A sortable relation must be a BelongsTo, or a HasOne, MorphOne or HasOneThrough declared with latestOfMany() or ofMany()',
                    UserResource::class,
                ),
                (string) $exception->getErrors()[0],
            );
        }
    }

    /**
     * Test that a query over an aliased table or a union, which the subquery
     * cannot correlate with, is refused.
     *
     * @return void
     */
    public function testAliasedTableAndUnionAreRefused(): void
    {
        $target  = $this->aggregate('posts', AggregateFunction::COUNT);
        $refuse  = sprintf('A relation aggregate cannot order a query over "%s" that reads another table or a union', User::class);
        $aliased = User::query();
        $union   = User::query();

        $aliased->getQuery()->from('users as members');
        $union->getQuery()->union(User::query()->toBase());

        foreach ([$aliased, $union] as $query) {
            try {
                $this->order()->apply($query, $target, 'asc', UserResource::class);
                self::fail('Expected a LogicException for a query the subquery cannot correlate with.');
            } catch (\LogicException $exception) {
                self::assertSame($refuse, $exception->getMessage());
            }
        }
    }

    /**
     * Test that a column target is refused, as it names no aggregate.
     *
     * @return void
     */
    public function testColumnTargetIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "name" key orders by a column, not a relation aggregate');

        $this->order()->apply(User::query(), SortTarget::column('name'), 'asc', UserResource::class);
    }

    /**
     * Test that only a belongs-to and a one-of-many to-one relation render the
     * single row an order reads.
     *
     * @return void
     */
    public function testOnlyBelongsToAndOneOfManyRelationsRenderOneRow(): void
    {
        $user = new User;
        $post = new AuthoredPost;

        self::assertTrue(RelationAggregateOrder::rendersOneRow($user->organization()));
        self::assertTrue(RelationAggregateOrder::rendersOneRow($user->latestArticle()));
        self::assertTrue(RelationAggregateOrder::rendersOneRow($post->pinnedNote()->latestOfMany()));
        self::assertTrue(RelationAggregateOrder::rendersOneRow($post->authorProfile()->latestOfMany()));
        self::assertFalse(RelationAggregateOrder::rendersOneRow($user->profile()));
        self::assertFalse(RelationAggregateOrder::rendersOneRow($post->pinnedNote()));
        self::assertFalse(RelationAggregateOrder::rendersOneRow($post->authorProfile()));
        self::assertFalse(RelationAggregateOrder::rendersOneRow($user->posts()));
        self::assertFalse(RelationAggregateOrder::rendersOneRow((new Note)->noteable()));
    }

    /**
     * Assert that ordering the query by the target matches the aggregate the
     * reference query selects, with the direction on the order entry.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\SortTarget  $target
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $reference
     * @return void
     */
    private function assertOrdersLike(Builder $query, SortTarget $target, Builder $reference): void
    {
        $this->order()->apply($query, $target, 'desc', UserResource::class);

        $columns  = $reference->getQuery()->getColumns();
        $selected = (string) end($columns);
        $orders   = $query->getQuery()->orders ?? [];

        self::assertCount(1, $orders);
        self::assertSame('desc', $orders[0]['direction']);
        self::assertSame($this->normalise(substr($selected, 0, (int) strrpos($selected, ' as '))), $this->orderSql($query));
        self::assertSame($reference->getQuery()->bindings['select'], $query->getQuery()->bindings['order']);
    }

    /**
     * Return the SQL of the query's first order, its aliases normalised.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return string
     */
    private function orderSql(Builder $query): string
    {
        $orders = $query->getQuery()->orders ?? [];

        return $this->normalise((string) $query->getQuery()->getGrammar()->getValue($orders[0]['column'] ?? ''));
    }

    /**
     * Number every self-relation alias from zero, as each loader counts its
     * own.
     *
     * @param  string  $sql
     * @return string
     */
    private function normalise(string $sql): string
    {
        return (string) preg_replace('/laravel_reserved_\d+/', 'laravel_reserved_0', $sql);
    }

    /**
     * Build an aggregate target over the given relation.
     *
     * @param  string  $relation
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string|null  $column
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null  $constraint
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\SortTarget
     */
    private function aggregate(string $relation, AggregateFunction $function, ?string $column = null, ?\Closure $constraint = null): SortTarget
    {
        return SortTarget::aggregate(new CompiledAggregateDefinition('key', $relation, $function, $column, $constraint, false, []));
    }

    /**
     * Build the order over a gate that opens the articles' trashed rows.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationAggregateOrder
     */
    private function order(): RelationAggregateOrder
    {
        assert($this->app !== null);

        $gate = new RelationTrashedGate($this->app->make(SchemaIntrospectionProvider::class), new Request, [Article::class => ArticleResource::class]);

        return new RelationAggregateOrder($gate);
    }
}
