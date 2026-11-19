<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use Illuminate\Contracts\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\BaseDefinition;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Scope;
use SineMacula\ApiToolkit\Schema\Sum;
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

    /** @var string The defect refusing a row-guarded field on the query surface */
    private const string ROW_GUARDED = 'A field with a row guard may not be %s, as a query would reveal what the guard hides; '
        . 'guard it with visibleTo() where visibility depends on the caller alone';

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
     * Test that a key PHP stores as an integer is rejected, since it can turn
     * the payload into a JSON list, while a digit string PHP keeps passes.
     *
     * @return void
     */
    public function testRejectsAKeyThatReadsAsAnInteger(): void
    {
        $errors = $this->collectErrors([
            ...Field::set(Count::of('posts', '0'), Sum::of('posts', 'id', '12'), Count::of('posts', '01'), Count::of('posts', '+1')),
            '__aggregate__:3'   => ['metric' => 'count', 'relation' => 'posts'],
            '__aggregate__:-3'  => ['metric' => 'count', 'relation' => 'posts'],
            '__aggregate__:1.0' => ['metric' => 'count', 'relation' => 'posts'],
        ]);

        $integer = 'Aggregate key "%s" reads as an integer, which can turn the aggregates object into a JSON list';
        $word    = 'Aggregate key "%s" may hold only letters, digits and underscores';

        self::assertSame([
            [self::RESOURCE, '__aggregate__:0', sprintf($integer, '0')],
            [self::RESOURCE, '__aggregate__:12', sprintf($integer, '12')],
            [self::RESOURCE, '__aggregate__:+1', sprintf($word, '+1')],
            [self::RESOURCE, '__aggregate__:3', sprintf($integer, '3')],
            [self::RESOURCE, '__aggregate__:-3', sprintf($word, '-3')],
            [self::RESOURCE, '__aggregate__:-3', sprintf($integer, '-3')],
            [self::RESOURCE, '__aggregate__:1.0', sprintf($word, '1.0')],
        ], $errors);
    }

    /**
     * Provide each schema declaring a field under a key the response writes
     * itself, with that key and its defect.
     *
     * @return iterable<string, array{array<string, array<string, mixed>>, string, string}>
     */
    public static function reservedKeyProvider(): iterable
    {
        $aggregate = ['__aggregate__:posts_count' => ['metric' => 'count', 'relation' => 'posts']];

        yield 'the type' => [['_type' => ['accessor' => 'kind']], '_type', 'The key "_type" is reserved for the resource type'];
        yield 'the truncated relations' => [['_truncated' => ['accessor' => 'cut']], '_truncated', 'The key "_truncated" is reserved for the relations a response truncates'];
        yield 'the aggregates beside an aggregate' => [
            ['aggregates' => ['accessor' => 'totals'], ...$aggregate],
            'aggregates',
            'The key "aggregates" is reserved for the relation aggregates the resource declares',
        ];
        yield 'the aggregates as an unrecognised metric' => [
            [...$aggregate, 'aggregates' => ['metric' => 'median']],
            'aggregates',
            'The key "aggregates" is reserved for the relation aggregates the resource declares',
        ];
    }

    /**
     * Test that a field under a key the rendered resource writes itself is
     * rejected, as the response would silently overwrite it.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  string  $key
     * @param  string  $defect
     * @return void
     */
    #[DataProvider('reservedKeyProvider')]
    public function testRefusesAFieldUnderAKeyTheResponseWrites(array $schema, string $key, string $defect): void
    {
        self::assertSame([[self::RESOURCE, $key, $defect]], $this->collectErrors($schema));
    }

    /**
     * Test that every reserved key one schema declares is reported.
     *
     * @return void
     */
    public function testRefusesEveryReservedKeyOfOneSchema(): void
    {
        $errors = $this->collectErrors([
            '_type'                     => ['accessor' => 'kind'],
            'aggregates'                => ['accessor' => 'totals'],
            '_truncated'                => ['accessor' => 'cut'],
            '__aggregate__:posts_count' => ['metric' => 'count', 'relation' => 'posts'],
        ]);

        self::assertSame(['_type', '_truncated', 'aggregates'], array_column($errors, 1));
    }

    /**
     * Test that a field keyed aggregates passes where no aggregate is declared,
     * an unrecognised metric included, as does an aggregate under that key.
     *
     * @return void
     */
    public function testAcceptsAnAggregatesKeyThePayloadNeverWrites(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid(['aggregates' => ['accessor' => 'totals'], 'score' => ['metric' => 'median']], self::RESOURCE);
        RawSchemaValidator::assertValid(['aggregates' => ['metric' => 'count', 'relation' => 'posts']], self::RESOURCE);
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
     * Test that caller guards declared as anything other than a list of
     * callables are reported.
     *
     * @return void
     */
    public function testReportsCallerGuardsThatAreNotCallableLists(): void
    {
        $errors = $this->collectErrors([
            'name'  => ['accessor' => 'name', 'caller_guards' => static fn (): bool => true],
            'email' => ['accessor' => 'email', 'caller_guards' => [static fn (): bool => true, 'not_a_function']],
        ]);

        self::assertSame([
            [self::RESOURCE, 'name', 'Caller guards must be a list of callables'],
            [self::RESOURCE, 'email', 'Caller guard at index 1 is not callable'],
        ], $errors);
    }

    /**
     * Provide one query declaration of each kind.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Schema\BaseDefinition, string}>
     */
    public static function rowGuardedQueryDeclarations(): iterable
    {
        yield 'filterable' => [Field::scalar('salary')->filterable(Capability::RANGE), 'filterable'];
        yield 'sortable' => [Field::scalar('salary')->sortable(), 'sortable'];
        yield 'sortable aggregate' => [Count::of('posts')->sortable('Leaderboard'), 'sortable'];
        yield 'sortable relation' => [Relation::to('organization', OrganizationResource::class)->sortable('Back-office list'), 'sortable'];
        yield 'searchable' => [Field::scalar('email')->searchable(SearchStrategy::SUBSTRING), 'searchable'];
        yield 'traversable' => [Relation::to('posts', PostResource::class)->traversable(), 'traversable'];
    }

    /**
     * Test that a row guard refuses each declaration that would let a query
     * reach the values it hides.
     *
     * @param  \SineMacula\ApiToolkit\Schema\BaseDefinition  $definition
     * @param  string  $declaration
     * @return void
     */
    #[DataProvider('rowGuardedQueryDeclarations')]
    public function testRefusesARowGuardedFieldOnTheQuerySurface(BaseDefinition $definition, string $declaration): void
    {
        $errors = $this->collectErrors(Field::set($definition->guard(static fn (): bool => true)));

        self::assertSame([[self::RESOURCE, array_key_first($definition->toArray()), sprintf(self::ROW_GUARDED, $declaration)]], $errors);
    }

    /**
     * Test that a row guard reports every query declaration a field makes.
     *
     * @return void
     */
    public function testRefusesEveryQueryDeclarationOfOneRowGuardedField(): void
    {
        $errors = $this->collectErrors(Field::set(
            Field::scalar('email')->guard(static fn (): bool => true)->filterable(Capability::EXACT)->sortable()->searchable(SearchStrategy::PREFIX),
        ));

        self::assertSame([
            [self::RESOURCE, 'email', sprintf(self::ROW_GUARDED, 'filterable')],
            [self::RESOURCE, 'email', sprintf(self::ROW_GUARDED, 'sortable')],
            [self::RESOURCE, 'email', sprintf(self::ROW_GUARDED, 'searchable')],
        ], $errors);
    }

    /**
     * Test that a row guard leaves alone a query marker that declares nothing.
     *
     * @return void
     */
    public function testAcceptsARowGuardedFieldWhoseQueryMarkerDeclaresNothing(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid(['salary' => ['guards' => [static fn (): bool => true], 'sortable' => false]], self::RESOURCE);
    }

    /**
     * Test that a caller guard refuses a searchable declaration, since a search
     * matches the same columns for every caller.
     *
     * @return void
     */
    public function testRefusesACallerGuardedSearchableField(): void
    {
        $errors = $this->collectErrors(Field::set(
            Field::scalar('email')->visibleTo(static fn (): bool => true)->searchable(SearchStrategy::SUBSTRING),
        ));

        self::assertSame([
            [self::RESOURCE, 'email', 'A field with a caller guard may not be searchable, as a search matches the same columns for every caller'],
        ], $errors);
    }

    /**
     * Test that a caller guard admits filterable, sortable and traversable
     * declarations, and an empty row guard list declares no row guard.
     *
     * @return void
     */
    public function testAcceptsCallerGuardedFilterSortAndTraversalDeclarations(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid([
            ...Field::set(
                Field::scalar('salary')->visibleTo(static fn (): bool => true)->filterable(Capability::RANGE)->sortable(),
                Relation::to('posts', PostResource::class)->visibleTo(static fn (): bool => true)->traversable(),
            ),
            'name' => ['filterable' => 'name', 'capability' => Capability::EXACT, 'guards' => []],
        ], self::RESOURCE);
    }

    /**
     * Provide two capabilities for one column, in both orders.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, \SineMacula\ApiToolkit\Enums\Capability}>
     */
    public static function conflictingCapabilities(): iterable
    {
        yield 'exact then range' => [Capability::EXACT, Capability::RANGE];
        yield 'range then exact' => [Capability::RANGE, Capability::EXACT];
    }

    /**
     * Test that one column filterable with two capabilities is refused on the
     * later declaration, in either order.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $first
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $second
     * @return void
     */
    #[DataProvider('conflictingCapabilities')]
    public function testRefusesOneColumnFilterableWithTwoCapabilities(Capability $first, Capability $second): void
    {
        $errors = $this->collectErrors(Field::set(
            Field::scalar('salary')->filterable($first),
            Field::scalar('salary', 'pay')->visibleTo(static fn (): bool => true)->filterable($second),
        ));

        self::assertSame([[self::RESOURCE, 'pay', sprintf(
            'Field is declared filterable against "salary" with the "%s" capability, and field "salary" declares the same column '
            . 'with "%s", so the column would answer a different set of operators for each',
            $second->value,
            $first->value,
        )]], $errors);
    }

    /**
     * Test that a repeated capability is read past to the declaration that
     * changes it, and every conflicting column is reported.
     *
     * @return void
     */
    public function testReadsPastARepeatedCapabilityToEachChange(): void
    {
        $errors = $this->collectErrors([
            'status'    => ['filterable' => 'status', 'capability' => Capability::EXACT],
            'state'     => ['filterable' => 'status', 'capability' => Capability::EXACT],
            'condition' => ['filterable' => 'status', 'capability' => Capability::RANGE],
            'kind'      => ['filterable' => 'kind', 'capability' => Capability::EXACT],
            'sort'      => ['filterable' => 'kind', 'capability' => Capability::ENUM],
        ]);

        self::assertSame(['condition', 'sort'], array_column($errors, 1));
    }

    /**
     * Test that a declaration without a capability, and one capability repeated
     * under differing caller guards, are no conflict.
     *
     * @return void
     */
    public function testAcceptsARepeatedCapabilityAndACapabilitylessDeclaration(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid([
            'name'   => ['filterable' => 'name'],
            'label'  => ['filterable' => 'name', 'capability' => Capability::EXACT],
            'salary' => ['filterable' => 'salary', 'capability' => Capability::RANGE, 'caller_guards' => [static fn (): bool => true]],
            'pay'    => ['filterable' => 'salary', 'capability' => Capability::RANGE, 'caller_guards' => [static fn (): bool => false]],
            'secret' => ['filterable' => 'salary', 'capability' => 'range'],
        ], self::RESOURCE);
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
     * Test that a raw pivot that is not a list of bare column names is refused
     * naming each field and entry, while a bare list or none passes.
     *
     * @return void
     */
    public function testReportsEveryPivotThatIsNotAListOfBareColumnNames(): void
    {
        $errors = $this->collectErrors([
            'members'  => ['relation' => 'members', 'pivot' => ['role', '_joined_at2']],
            'teams'    => ['relation' => 'teams', 'pivot' => null],
            'tags'     => ['relation' => 'tags', 'pivot' => 'role'],
            'labels'   => ['relation' => 'labels', 'pivot' => ['first' => 'role']],
            'projects' => ['relation' => 'projects', 'pivot' => ['role', 7, 'team_user.role', 'role as r', "role\n", '2nd']],
        ]);

        self::assertSame([
            [self::RESOURCE, 'tags', 'Pivot must be a list of column names'],
            [self::RESOURCE, 'labels', 'Pivot must be a list of column names'],
            [self::RESOURCE, 'projects', 'Pivot column at index 1 must be a bare column name'],
            [self::RESOURCE, 'projects', 'Pivot column at index 2 must be a bare column name'],
            [self::RESOURCE, 'projects', 'Pivot column at index 3 must be a bare column name'],
            [self::RESOURCE, 'projects', 'Pivot column at index 4 must be a bare column name'],
            [self::RESOURCE, 'projects', 'Pivot column at index 5 must be a bare column name'],
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
            'writers' => ['relation' => 'user', 'accessor' => 'name', 'traversable' => 'posts.writer'],
        ]);

        self::assertSame([
            [self::RESOURCE, 'organization', 'Relation "user.organization" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'tags', 'Relation "posts.tags" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'authors', 'Relation "posts.author" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'authors', 'Relation "posts.user" names a nested path; declare each segment as a relation of its own resource'],
            [self::RESOURCE, 'writers', 'Relation "posts.writer" names a nested path; declare each segment as a relation of its own resource'],
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
     * Test that a scope argument whose root is no PHP identifier is refused,
     * while a list argument and its items are one argument.
     *
     * @return void
     */
    public function testRefusesAScopeArgumentThatCannotBindToAParameter(): void
    {
        $errors = $this->collectErrors(Field::set(
            Scope::of('identified')->parameters(['ids' => 'array', 'ids.*' => 'integer']),
            Scope::of('near')->parameters(['lat' => 'numeric', '1st' => 'integer', 'max-km' => 'integer', '*.km' => 'integer']),
            ['__scope__:broken' => ['scope' => 'broken', 'parameters' => 'lat']],
            ['score' => ['parameters' => ['1st' => 'integer']]],
        ));

        $defect = 'Scope argument "%s" must be a PHP identifier, as it binds to the scope parameter of that name';

        self::assertSame([
            [self::RESOURCE, '__scope__:near', sprintf($defect, '1st')],
            [self::RESOURCE, '__scope__:near', sprintf($defect, 'max-km')],
            [self::RESOURCE, '__scope__:near', sprintf($defect, '*')],
            [self::RESOURCE, '__scope__:broken', 'Scope parameters must be an array of validation rules keyed by argument'],
        ], $errors);
    }

    /**
     * Provide raw sortable aggregates and relations carrying no usable reason.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function reasonlessSortProvider(): iterable
    {
        yield 'aggregate without a reason' => [['metric' => 'count', 'relation' => 'posts', 'sortable' => true]];
        yield 'aggregate with a blank reason' => [['metric' => 'count', 'relation' => 'posts', 'sortable' => true, 'unindexed' => ' ']];
        yield 'relation with a non-string reason' => [['relation' => 'posts', 'resource' => PostResource::class, 'sortable' => true, 'unindexed' => true]];
    }

    /**
     * Test that a raw sortable aggregate or relation must carry a reason.
     *
     * @param  array<string, mixed>  $definition
     * @return void
     */
    #[DataProvider('reasonlessSortProvider')]
    public function testRefusesASortableAggregateOrRelationWithoutAReason(array $definition): void
    {
        self::assertSame(
            [[self::RESOURCE, 'posts', 'A sortable relation or aggregate must carry the reason its order is unindexed']],
            $this->collectErrors(['posts' => $definition]),
        );
    }

    /**
     * Test that a sortable relation must render a resource, run no constraint
     * and not take the aggregate key prefix as its name.
     *
     * @return void
     */
    public function testRefusesASortableRelationItsOrderCannotHonour(): void
    {
        $errors = $this->collectErrors(Field::set(
            Relation::to('organization', static fn (): null => null)->sortable('Back-office list'),
            Relation::to('posts', PostResource::class)->constrain(self::constraint())->sortable('Back-office list'),
            Relation::to('aggregates', OrganizationResource::class, 'grouped')->sortable('Back-office list'),
        ));

        self::assertSame([
            [self::RESOURCE, 'organization', 'A sortable relation must render a child resource, whose sortable columns it orders by'],
            [self::RESOURCE, 'posts', 'A sortable relation may not be constrained, as its order would read the rows the constraint hides'],
            [self::RESOURCE, 'grouped', 'A sortable relation may not be named "aggregates", which prefixes the aggregate order keys'],
        ], $errors);
    }

    /**
     * Test that every defect of one sortable relation is reported.
     *
     * @return void
     */
    public function testReportsEveryDefectOfOneSortableRelation(): void
    {
        $errors = $this->collectErrors(['aggregates' => ['relation' => 'aggregates', 'constraint' => self::constraint(), 'sortable' => true]]);

        self::assertSame([
            [self::RESOURCE, 'aggregates', 'A sortable relation or aggregate must carry the reason its order is unindexed'],
            [self::RESOURCE, 'aggregates', 'A sortable relation must render a child resource, whose sortable columns it orders by'],
            [self::RESOURCE, 'aggregates', 'A sortable relation may not be constrained, as its order would read the rows the constraint hides'],
            [self::RESOURCE, 'aggregates', 'A sortable relation may not be named "aggregates", which prefixes the aggregate order keys'],
        ], $errors);
    }

    /**
     * Test that a constrained sortable aggregate and a sortable relation over a
     * resource are accepted, and that a sortable marker on a plain field is
     * left to the column rules.
     *
     * @return void
     */
    public function testAcceptsSortableAggregatesAndRelationsItsOrderCanHonour(): void
    {
        $this->expectNotToPerformAssertions();

        RawSchemaValidator::assertValid([
            ...Field::set(
                Count::of('posts')->constrain(self::constraint())->sortable('Leaderboard'),
                Relation::to('organization', OrganizationResource::class)->sortable('Back-office list'),
            ),
            'flag' => ['sortable' => true],
        ], self::RESOURCE);
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
