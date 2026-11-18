<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use Illuminate\Contracts\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Validation\RawSchemaValidator;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use Tests\Fixtures\Resources\BoundedPostResource;
use Tests\Fixtures\Resources\CommentResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\QueryableTagResource;

/**
 * Tests for the RawSchemaValidator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(RawSchemaValidator::class)]
final class RawSchemaValidatorTest extends TestCase
{
    /** @var string The resource class the validated schemas are reported against */
    private const string RESOURCE = 'App\Http\Resources\UserResource';

    /** @var string The defect refusing a constrained traversable relation */
    private const string CONSTRAINED_TRAVERSAL = 'A traversable relation may not be constrained, as a filter would reach the rows it hides; '
        . 'move the row predicate into a relation method';

    /**
     * Test that Closure constraints with callable guards and transformers pass.
     *
     * @return void
     */
    public function testAcceptsClosureConstraintsAndCallableLists(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid([
            'name'           => ['accessor' => 'name', 'guards' => [static fn (): bool => true], 'transformers' => ['trim']],
            'comments_count' => ['metric' => 'count', 'relation' => 'comments', 'constraint' => static function (): void {}],
            'email'          => ['accessor' => 'email', 'constraint' => null],
        ], self::RESOURCE);
    }

    /**
     * Test that each non-callable or non-Closure entry is reported by position.
     *
     * @return void
     */
    public function testReportsEveryNonCallableEntryAndNonClosureConstraint(): void
    {
        $errors = $this->collectErrors([
            'name' => [
                'accessor' => 'name',
                'guards'   => ['first' => static fn (): bool => true, 'second' => 'not_a_function', 'third' => 'also_not_a_function'],
            ],
            'comments_count' => [
                'metric'       => 'count',
                'relation'     => 'comments',
                'transformers' => ['not_a_function'],
            ],
            'reviews_rating' => [
                'metric'     => 'sum',
                'relation'   => 'reviews',
                'column'     => 'rating',
                'constraint' => 'not-a-closure',
                'guards'     => ['not_a_function'],
            ],
        ]);

        self::assertSame([
            [self::RESOURCE, 'name', 'Guard at index 1 is not callable'],
            [self::RESOURCE, 'name', 'Guard at index 2 is not callable'],
            [self::RESOURCE, 'comments_count', 'Transformer at index 0 is not callable'],
            [self::RESOURCE, 'reviews_rating', 'Constraint must be a Closure'],
            [self::RESOURCE, 'reviews_rating', 'Guard at index 0 is not callable'],
        ], $errors);
    }

    /**
     * Test that a prefixed aggregate entry naming no recognised metric is
     * rejected, while an unprefixed one is left to compile as a field.
     *
     * @return void
     */
    public function testRejectsAnUnknownMetricOnAPrefixedAggregate(): void
    {
        $errors = $this->collectErrors([
            '__aggregate__:posts_median' => ['metric' => 'median', 'relation' => 'posts'],
            '__aggregate__:posts_bare'   => ['relation' => 'posts'],
            'score'                      => ['metric' => 'median'],
        ]);

        $defect = 'Aggregate metric must be one of count, exists, sum, avg, min, max';

        self::assertSame([
            [self::RESOURCE, '__aggregate__:posts_median', $defect],
            [self::RESOURCE, '__aggregate__:posts_bare', $defect],
        ], $errors);
    }

    /**
     * Test that a column function without a column and a columnless function
     * with one are both rejected.
     *
     * @return void
     */
    public function testRejectsAColumnMissingOrPresentAgainstTheFunction(): void
    {
        $errors = $this->collectErrors([
            '__aggregate__:orders_sum' => ['metric' => 'sum', 'relation' => 'orders'],
            '__aggregate__:orders_max' => ['metric' => 'max', 'relation' => 'orders', 'column' => ''],
            '__aggregate__:orders_min' => ['metric' => 'min', 'relation' => 'orders', 'column' => 'total'],
            '__aggregate__:posts_any'  => ['metric' => 'exists', 'relation' => 'posts', 'column' => 'id'],
            '__aggregate__:posts_n'    => ['metric' => 'count', 'relation' => 'posts'],
        ]);

        self::assertSame([
            [self::RESOURCE, '__aggregate__:orders_sum', 'The sum aggregate must name a related column'],
            [self::RESOURCE, '__aggregate__:orders_max', 'The max aggregate must name a related column'],
            [self::RESOURCE, '__aggregate__:posts_any', 'The exists aggregate takes no column'],
        ], $errors);
    }

    /**
     * Test that two raw entries resolving to one present key are rejected
     * whatever their functions.
     *
     * @return void
     */
    public function testRejectsTwoAggregatesWithOneEffectiveKey(): void
    {
        $errors = $this->collectErrors([
            '__aggregate__:posts' => ['metric' => 'count', 'relation' => 'posts'],
            'published'           => ['metric' => 'exists', 'relation' => 'posts', 'key' => 'posts'],
            'drafts'              => ['metric' => 'count', 'relation' => 'posts', 'key' => 'posts'],
        ]);

        self::assertSame([
            [self::RESOURCE, 'published', 'Aggregate key "posts" is already declared by "__aggregate__:posts"'],
            [self::RESOURCE, 'drafts', 'Aggregate key "posts" is already declared by "__aggregate__:posts"'],
        ], $errors);
    }

    /**
     * Test that an attribute PostgreSQL would truncate is rejected, while one
     * at the limit passes.
     *
     * @return void
     */
    public function testRejectsAnAttributeLongerThanSixtyThreeBytes(): void
    {
        $fits     = str_repeat('a', 53);
        $overflow = str_repeat('b', 54);

        $errors = $this->collectErrors([
            $fits     => ['metric' => 'count', 'relation' => 'posts'],
            $overflow => ['metric' => 'count', 'relation' => 'posts'],
        ]);

        self::assertSame([
            [self::RESOURCE, $overflow, sprintf('Aggregate attribute "aggregate_%s" is longer than 63 bytes, which PostgreSQL truncates', $overflow)],
        ], $errors);
    }

    /**
     * Test that a key breaking the relation alias or the SQL alias is rejected,
     * while a word key in any script, combining marks included, passes.
     *
     * @return void
     */
    public function testRejectsAKeyHoldingAnythingButWordCharacters(): void
    {
        $errors = $this->collectErrors([
            'posts_count_2'         => ['metric' => 'count', 'relation' => 'posts'],
            'beiträge'              => ['metric' => 'count', 'relation' => 'posts'],
            "i\u{0307}posts_count"  => ['metric' => 'count', 'relation' => 'posts'],
            "trailing\n"            => ['metric' => 'count', 'relation' => 'posts'],
            'post count'            => ['metric' => 'count', 'relation' => 'posts'],
            'post.count'            => ['metric' => 'count', 'relation' => 'posts'],
            '__aggregate__:aliased' => ['metric' => 'count', 'relation' => 'posts', 'key' => 'post:count'],
            "bad\xff"               => ['metric' => 'count', 'relation' => 'posts'],
        ]);

        self::assertSame([
            [self::RESOURCE, "trailing\n", "Aggregate key \"trailing\n\" may hold only letters, digits and underscores"],
            [self::RESOURCE, 'post count', 'Aggregate key "post count" may hold only letters, digits and underscores'],
            [self::RESOURCE, 'post.count', 'Aggregate key "post.count" may hold only letters, digits and underscores'],
            [self::RESOURCE, '__aggregate__:aliased', 'Aggregate key "post:count" may hold only letters, digits and underscores'],
            [self::RESOURCE, "bad\xff", "Aggregate key \"bad\xff\" may hold only letters, digits and underscores"],
        ], $errors);
    }

    /**
     * Test that guards or transformers declared as anything other than a list
     * are reported, while the other list is still checked.
     *
     * @return void
     */
    public function testReportsGuardsAndTransformersThatAreNotLists(): void
    {
        $errors = $this->collectErrors([
            'name' => [
                'accessor'     => 'name',
                'guards'       => static fn (): bool => true,
                'transformers' => ['not_a_function'],
            ],
            'email' => [
                'accessor'     => 'email',
                'transformers' => 'trim',
            ],
        ]);

        self::assertSame([
            [self::RESOURCE, 'name', 'Guards must be a list of callables'],
            [self::RESOURCE, 'name', 'Transformer at index 0 is not callable'],
            [self::RESOURCE, 'email', 'Transformers must be a list of callables'],
        ], $errors);
    }

    /**
     * Test that every defect of one aggregate entry is reported.
     *
     * @return void
     */
    public function testReportsEveryDefectOfOneAggregate(): void
    {
        $key = str_repeat('c', 54);

        $errors = $this->collectErrors([
            $key                       => ['metric' => 'count', 'relation' => 'posts'],
            '__aggregate__:duplicated' => ['metric' => 'sum', 'relation' => 'orders', 'key' => $key, 'constraint' => 'not-a-closure'],
        ]);

        $overflow = sprintf('Aggregate attribute "aggregate_%s" is longer than 63 bytes, which PostgreSQL truncates', $key);

        self::assertSame([
            [self::RESOURCE, $key, $overflow],
            [self::RESOURCE, '__aggregate__:duplicated', 'Constraint must be a Closure'],
            [self::RESOURCE, '__aggregate__:duplicated', 'The sum aggregate must name a related column'],
            [self::RESOURCE, '__aggregate__:duplicated', sprintf('Aggregate key "%s" is already declared by "%s"', $key, $key)],
            [self::RESOURCE, '__aggregate__:duplicated', $overflow],
        ], $errors);
    }

    /**
     * Test that a relation limit below 1 or not an integer is reported, while a
     * positive or absent one passes.
     *
     * @return void
     */
    public function testReportsEveryLimitThatIsNotAPositiveInteger(): void
    {
        $errors = $this->collectErrors([
            'posts'    => ['relation' => 'posts', 'limit' => 1],
            'comments' => ['relation' => 'comments', 'limit' => null],
            'tags'     => ['relation' => 'tags', 'limit' => 0],
            'articles' => ['relation' => 'articles', 'limit' => -1],
            'reviews'  => ['relation' => 'reviews', 'limit' => '5'],
        ]);

        self::assertSame([
            [self::RESOURCE, 'tags', 'Limit must be an integer of at least 1'],
            [self::RESOURCE, 'articles', 'Limit must be an integer of at least 1'],
            [self::RESOURCE, 'reviews', 'Limit must be an integer of at least 1'],
        ], $errors);
    }

    /**
     * Test that a constrained relation is refused when its own field, an
     * accessor-only sibling or another field's marker makes it traversable.
     *
     * @return void
     */
    public function testRefusesAConstrainedRelationAFilterTraverses(): void
    {
        $errors = $this->collectErrors([
            ...Field::set(
                Relation::to('posts', PostResource::class)->constrain(self::constraint())->traversable(),
                Relation::to('comments', CommentResource::class)->constrain(self::constraint()),
                Relation::to('comments', 'body', 'comment_bodies')->traversable(),
                Relation::to('tags', QueryableTagResource::class)->constrain(self::constraint()),
            ),
            'organization' => ['relation' => 'organization', 'accessor' => 'name', 'traversable' => 'tags'],
        ]);

        self::assertSame([
            [self::RESOURCE, 'posts', self::CONSTRAINED_TRAVERSAL],
            [self::RESOURCE, 'comments', self::CONSTRAINED_TRAVERSAL],
            [self::RESOURCE, 'tags', self::CONSTRAINED_TRAVERSAL],
        ], $errors);
    }

    /**
     * Provide schemas whose fields over one relation would each need their own
     * rows, with the defect each is refused with.
     *
     * @return iterable<string, array{array<string, array<string, mixed>>, string}>
     */
    public static function sharedRelationProvider(): iterable
    {
        yield 'one constrained' => [
            Field::set(
                Relation::to('posts', PostResource::class)->limit(5),
                Relation::to('posts', PostResource::class, 'latest_posts')->constrain(self::constraint()),
            ),
            self::sharedRelation('"posts", "latest_posts"', 'posts'),
        ];

        yield 'two resources' => [
            Field::set(
                Relation::to('posts', PostResource::class),
                Relation::to('posts', BoundedPostResource::class, 'bounded_posts'),
                Relation::to('posts', 'title', 'post_titles'),
            ),
            self::sharedRelation('"posts", "bounded_posts"', 'posts'),
        ];

        yield 'malformed resource beside a constraint' => [
            [
                'posts'        => ['relation' => 'posts', 'resource' => [PostResource::class], 'constraint' => self::constraint()],
                'pinned_posts' => ['relation' => 'posts', 'resource' => PostResource::class],
            ],
            self::sharedRelation('"posts", "pinned_posts"', 'posts'),
        ];

        yield 'array form' => [
            [
                'posts'        => ['relation' => ['posts', 'tags'], 'resource' => PostResource::class],
                'pinned_posts' => ['relation' => 'posts', 'resource' => BoundedPostResource::class],
            ],
            self::sharedRelation('"posts", "pinned_posts"', 'posts'),
        ];
    }

    /**
     * Test that fields sharing one relation are refused when one constrains it
     * or they render different resources, the defect naming every key.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  string  $defect
     * @return void
     */
    #[DataProvider('sharedRelationProvider')]
    public function testRefusesFieldsSharingARelationThatNeedTheirOwnRows(array $schema, string $defect): void
    {
        self::assertSame([[self::RESOURCE, (string) array_key_first($schema), $defect]], $this->collectErrors($schema));
    }

    /**
     * Test that fields sharing an unconstrained relation over one resource, an
     * accessor-only sibling and an aggregate beside a constraint all pass.
     *
     * @return void
     */
    public function testAcceptsFieldsSharingARelationThatLoadsOneSetOfRows(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid([
            ...Field::set(
                Relation::to('posts', PostResource::class)->fields(['id', 'tags']),
                Relation::to('posts', PostResource::class, 'post_authors')->fields(['id', 'user'])->traversable(),
                Relation::to('posts', 'title', 'post_titles')->traversable(),
                Relation::to('comments', CommentResource::class)->constrain(self::constraint()),
                Relation::to('comments', 'body', 'comment_bodies'),
                Count::of('comments')->constrain(self::constraint()),
            ),
            'organization' => ['relation' => 'organization', 'traversable' => 'organization'],
        ], self::RESOURCE);
    }

    /**
     * Test that a relation naming a nested path is refused, in either form and
     * as a traversable marker.
     *
     * @return void
     */
    public function testRefusesARelationNamingANestedPath(): void
    {
        $errors = $this->collectErrors([
            ...Field::set(Relation::to('user.organization', OrganizationResource::class, 'organization')->constrain(self::constraint())->traversable()),
            'tags'    => ['relation' => ['posts.tags', 'posts'], 'accessor' => 'name'],
            'authors' => ['relation' => 'posts.author', 'accessor' => 'name', 'traversable' => 'posts.user'],
        ]);

        self::assertSame([
            [self::RESOURCE, 'organization', 'Relation "user.organization" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'tags', 'Relation "posts.tags" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'authors', 'Relation "posts.author" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'authors', 'Relation "posts.user" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'organization', self::CONSTRAINED_TRAVERSAL],
        ], $errors);
    }

    /**
     * Test that every relation whose fields need their own rows is refused, not
     * only the first.
     *
     * @return void
     */
    public function testRefusesEverySharedRelationThatNeedsItsOwnRows(): void
    {
        $errors = $this->collectErrors(Field::set(
            Relation::to('organization', OrganizationResource::class),
            Relation::to('posts', PostResource::class),
            Relation::to('posts', BoundedPostResource::class, 'bounded_posts'),
            Relation::to('comments', CommentResource::class),
            Relation::to('comments', CommentResource::class, 'open_comments')->constrain(self::constraint()),
        ));

        self::assertSame([
            [self::RESOURCE, 'posts', self::sharedRelation('"posts", "bounded_posts"', 'posts')],
            [self::RESOURCE, 'comments', self::sharedRelation('"comments", "open_comments"', 'comments')],
        ], $errors);
    }

    /**
     * Return the errors for the raw schema as class, key and defect triples.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return list<array{string, string, string}>
     */
    private function collectErrors(array $rawSchema): array
    {
        try {
            RawSchemaValidator::assertValid($rawSchema, self::RESOURCE);
        } catch (InvalidSchemaException $exception) {
            return array_values(array_map(
                static fn (SchemaValidationError $error): array => [$error->resourceClass, $error->fieldKey, $error->defect],
                $exception->getErrors(),
            ));
        }

        self::fail('Expected InvalidSchemaException to be thrown.');
    }

    /**
     * Return a constraint callback for a relation or aggregate.
     *
     * @return \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void
     */
    private static function constraint(): \Closure
    {
        return static function (Builder $query): void {
            $query->where('published', true);
        };
    }

    /**
     * Build the defect refusing the given keys sharing a relation.
     *
     * @param  string  $keys
     * @param  string  $relation
     * @return string
     */
    private static function sharedRelation(string $keys, string $relation): string
    {
        return sprintf(
            'Fields %s share the "%s" relation, which loads once, so none may be constrained and all must render one resource; declare a relation method for each',
            $keys,
            $relation,
        );
    }
}
