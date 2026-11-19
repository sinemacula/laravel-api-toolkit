<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Contracts\DeclaresValueShape;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\InOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Device;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\DeepTraversalPostResource;
use Tests\Fixtures\Resources\DeviceResource;
use Tests\Fixtures\Resources\GatheringResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicAuthorPostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the FilterApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
final class FilterApplierTest extends TestCase
{
    use InteractsWithNonPublicMembers, LocalisesToolkitMessages;

    /** @var string */
    private const string OPERATOR_CONTAINS = '$contains';

    /** @var string */
    private const string BETWEEN_BOUNDS = 'The $between condition on "id" must be a list of two values, [low, high].';

    /** @var string */
    private const string NOT_A_PLATFORM = 'must be one of web, mobile_app, desktop.';

    /** @var string */
    private const string CANNOT_BE_NULL = 'cannot be null; $null matches a missing value and $notNull a present one.';

    /** @var string */
    private const string TAKES_ONLY_TRUE = 'takes only true; $null matches a missing value and $notNull a present one.';

    /** @var array<string, \SineMacula\ApiToolkit\Enums\Capability> */
    private const array DEVICE_FILTERABLE = [
        'name'     => Capability::EXACT,
        'platform' => Capability::ENUM,
        'status'   => Capability::ENUM,
    ];

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry */
    private OperatorRegistry $operatorRegistry;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier */
    private FilterApplier $applier;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->operatorRegistry = $this->app->make(OperatorRegistry::class);

        $this->applier = new FilterApplier(new FilterValueGuard(new ColumnLiteral($this->app->make(SchemaIntrospectionProvider::class))));
    }

    /**
     * Test that apply with null filters returns an unmodified query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullFiltersReturnsUnmodifiedQuery(): void
    {
        $result = $this->applyFilters(null);

        self::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that apply with empty filters returns an unmodified query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithEmptyFiltersReturnsUnmodifiedQuery(): void
    {
        $result = $this->applyFilters([]);

        self::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that apply with a simple filter applies a where clause.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithSimpleFilterAppliesWhereClause(): void
    {
        $result = $this->applyFilters(['name' => 'Alice']);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('name', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that $eq operator applies an equals condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithEqOperatorAppliesEqualsCondition(): void
    {
        $result = $this->applyFilters(['name' => ['$eq' => 'Alice']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Basic', $wheres[0]['type']);
        self::assertSame('=', $wheres[0]['operator']);
        self::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that $neq operator applies a not-equals condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNeqOperatorAppliesNotEqualsCondition(): void
    {
        $result = $this->applyFilters(['status' => ['$neq' => 'active']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('<>', $wheres[0]['operator']);
    }

    /**
     * Test that $gt operator applies a greater-than condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithGtOperatorAppliesGreaterThan(): void
    {
        $result = $this->applyFilters(['id' => ['$gt' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('>', $wheres[0]['operator']);
    }

    /**
     * Test that $lt operator applies a less-than condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithLtOperatorAppliesLessThan(): void
    {
        $result = $this->applyFilters(['id' => ['$lt' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('<', $wheres[0]['operator']);
    }

    /**
     * Test that $ge operator applies a greater-than-or-equal condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithGeOperatorAppliesGreaterThanOrEqual(): void
    {
        $result = $this->applyFilters(['id' => ['$ge' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('>=', $wheres[0]['operator']);
    }

    /**
     * Test that $le operator applies a less-than-or-equal condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithLeOperatorAppliesLessThanOrEqual(): void
    {
        $result = $this->applyFilters(['id' => ['$le' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('<=', $wheres[0]['operator']);
    }

    /**
     * Test that $in operator uses whereIn.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithInOperatorUsesWhereIn(): void
    {
        $result = $this->applyFilters(['name' => ['$in' => ['Alice', 'Bob']]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('In', $wheres[0]['type']);
        self::assertSame(['Alice', 'Bob'], $wheres[0]['values']);
    }

    /**
     * Test that $between operator uses whereBetween.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithBetweenOperatorUsesWhereBetween(): void
    {
        $result = $this->applyFilters(['id' => ['$between' => [1, 10]]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('between', $wheres[0]['type']);
    }

    /**
     * Test that $between without exactly two values is refused, not dropped.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithBetweenWrongArraySizeIsRefused(): void
    {
        $this->assertRefusedAt(['id' => ['$between' => [1]]], 'filters.id.$between', self::BETWEEN_BOUNDS);
    }

    /**
     * Test that $null operator adds whereNull.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullOperatorAddsWhereNull(): void
    {
        $result = $this->applyFilters(['organization_id' => ['$null' => true]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Null', $wheres[0]['type']);
        self::assertSame('organization_id', $wheres[0]['column']);
    }

    /**
     * Test that $notNull operator adds whereNotNull.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNotNullOperatorAddsWhereNotNull(): void
    {
        $result = $this->applyFilters(['organization_id' => ['$notNull' => true]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('NotNull', $wheres[0]['type']);
    }

    /**
     * Test that $contains with an array uses whereJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithContainsArrayUsesWhereJsonContains(): void
    {
        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => ['php']]]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that a comma-separated $contains adds several conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithContainsCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $query = $this->applyFilters(['name' => 'Alice', 'context' => [self::OPERATOR_CONTAINS => 'php,rust']]);

        $contains = 'exists (select 1 from json_each("context") where "json_each"."value" is ?)';

        self::assertStringEndsWith('where "name" = ? and (' . $contains . ' or ' . $contains . ')', $query->toSql());
        self::assertSame(['Alice', 'php', 'rust'], $query->getBindings());
    }

    /**
     * Test that $contains with a plain string uses whereJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithContainsPlainStringUsesWhereJsonContains(): void
    {
        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => 'php']]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $has operator adds whereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasOperatorAddsWhereHas(): void
    {
        $result = $this->applyFilters(['$has' => ['posts']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that $hasnt operator adds whereDoesntHave.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasntOperatorAddsWhereDoesntHave(): void
    {
        $result = $this->applyFilters(['$hasnt' => ['posts']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that $has with a named relation and conditions applies whereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasNamedRelationAndConditions(): void
    {
        $result = $this->applyFilters([
            '$has' => [
                'posts' => ['title' => ['$eq' => 'test']],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);

        /** @var \Illuminate\Database\Query\Builder $subQuery */
        $subQuery = $wheres[0]['query'];

        $subWheres = $this->relationWheres($subQuery);

        self::assertTrue(
            $subWheres->contains(fn (array $where): bool => ($where['column'] ?? null) === 'title' && ($where['value'] ?? null) === 'test'),
        );
    }

    /**
     * Test that an $or group joins its documents with OR.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrLogicalOperatorGroupsConditions(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['name' => 'Alice'], ['email' => 'bob@example.com']]],
            '(("name" = ?) or ("email" = ?))',
            ['Alice', 'bob@example.com'],
        );
    }

    /**
     * Test that an $and group joins its documents with AND.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithAndLogicalOperatorGroupsConditions(): void
    {
        $this->assertCompilesTo(
            ['$and' => [['name' => 'Alice'], ['email' => 'alice@example.com']]],
            '(("name" = ?) and ("email" = ?))',
            ['Alice', 'alice@example.com'],
        );
    }

    /**
     * Test that a key beside a group joins it with AND in either order.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAKeyBesideAGroupJoinsItWithAndInEitherOrder(): void
    {
        $group = [['name' => 'Alice'], ['email' => 'bob@example.com']];

        $this->assertCompilesTo(
            ['status' => 'active', '$or' => $group],
            '"status" = ? and (("name" = ?) or ("email" = ?))',
            ['active', 'Alice', 'bob@example.com'],
        );

        $this->assertCompilesTo(
            ['$or' => $group, 'status' => 'active'],
            '(("name" = ?) or ("email" = ?)) and "status" = ?',
            ['Alice', 'bob@example.com', 'active'],
        );
    }

    /**
     * Test that an $or of multi-key documents ANDs within each document.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnOrOfMultiKeyDocumentsAndsWithinEachDocument(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['name' => 'Alice', 'status' => 'active'], ['name' => 'Bob', 'status' => 'banned']]],
            '(("name" = ? and "status" = ?) or ("name" = ? and "status" = ?))',
            ['Alice', 'active', 'Bob', 'banned'],
        );
    }

    /**
     * Test that an $and of two $or groups keeps each disjunction whole.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnAndOfTwoOrGroupsKeepsEachDisjunctionWhole(): void
    {
        $this->assertCompilesTo(
            ['$and' => [
                ['$or' => [['name' => 'Alice'], ['name' => 'Bob']]],
                ['$or' => [['status' => 'active'], ['email' => 'carol@example.com']]],
            ]],
            '(((("name" = ?) or ("name" = ?))) and ((("status" = ?) or ("email" = ?))))',
            ['Alice', 'Bob', 'active', 'carol@example.com'],
        );
    }

    /**
     * Test that an $and inside an $or document keeps AND inside and OR between.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnAndInsideAnOrDocumentKeepsAndInsideAndOrBetween(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['name' => 'Alice'], ['$and' => [['email' => 'bob@example.com'], ['status' => 'active']]]]],
            '(("name" = ?) or ((("email" = ?) and ("status" = ?))))',
            ['Alice', 'bob@example.com', 'active'],
        );
    }

    /**
     * Test that the conditions on one column inside an $or document are ANDed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionsOnOneColumnInsideAnOrDocumentAreAnded(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['id' => ['$gt' => 1, '$lt' => 5]], ['name' => 'Alice']]],
            '(("id" > ? and "id" < ?) or ("name" = ?))',
            [1, 5, 'Alice'],
        );
    }

    /**
     * Test that a relation filter applies whereHas with its conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithRelationFilterAppliesWhereHas(): void
    {
        $this->assertCompilesTo(
            ['posts' => ['title' => ['$eq' => 'test'], 'id' => 7]],
            'exists (select * from "posts" where "users"."id" = "posts"."user_id" and "title" = ? and "id" = ?)',
            ['test', 7],
        );
    }

    /**
     * Test that a relation key inside an $or document attaches with OR.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARelationKeyInsideAnOrDocumentAttachesWithOr(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['name' => 'Alice'], ['posts' => ['id' => 1]]]],
            '(("name" = ?) or (exists (select * from "posts" where "users"."id" = "posts"."user_id" and "id" = ?)))',
            ['Alice', 1],
        );
    }

    /**
     * Test that a relation key inside a group checks its columns against the
     * related resource, never the root.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARelationKeyInsideAGroupChecksItsColumnsAgainstTheRelatedResource(): void
    {
        $this->assertRejectsKey(['$or' => [['name' => 'Alice'], ['posts' => ['email' => 'bob@example.com']]]], 'email');
    }

    /**
     * Test that a relation of a related resource compiles to a nested exists.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARelationOfARelatedResourceCompilesToANestedExists(): void
    {
        $this->assertCompilesTo(
            ['posts' => ['user' => ['name' => 'Alice']]],
            'exists (select * from "posts" where "users"."id" = "posts"."user_id" and exists (select * from "users" where "posts"."user_id" = "users"."id" and "name" = ?))',
            ['Alice'],
        );
    }

    /**
     * Test that an $or inside a relation filter is grouped beside the
     * correlation predicate.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrInsideRelationFilterKeepsTheCorrelationPredicate(): void
    {
        $this->assertCompilesTo(
            ['posts' => ['$or' => [['title' => 'test'], ['id' => 1]], 'id' => 7]],
            'exists (select * from "posts" where "users"."id" = "posts"."user_id" and (("title" = ?) or ("id" = ?)) and "id" = ?)',
            ['test', 1, 7],
        );
    }

    /**
     * Test that an empty relation document compiles to an existence check.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEmptyRelationDocumentIsAnExistenceCheck(): void
    {
        $this->assertCompilesTo(['posts' => []], 'exists (select * from "posts" where "users"."id" = "posts"."user_id")', []);
    }

    /**
     * Test that a custom operator adding no clause is refused, beside another
     * condition and inside a relation.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCustomOperatorAddingNoClauseIsRefused(): void
    {
        $this->operatorRegistry->register('$noop', static function (): void {}, Capability::EXACT);

        $this->assertRefusedAt(['name' => ['$eq' => 'Alice', '$noop' => 'x']], 'filters.name.$noop', self::addsNothing('filters.name.$noop'));
        $this->assertRefusedAt(['posts' => ['title' => ['$noop' => 'x']]], 'filters.posts.title.$noop', self::addsNothing('filters.posts.title.$noop'));
    }

    /**
     * Test that a custom operator joining its clause with OR cannot disjoin the
     * clauses beside it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCustomOperatorJoiningWithOrIsGroupedOnItsOwn(): void
    {
        $this->operatorRegistry->register('$either', static function (Builder $query, string $column, mixed $value): void {
            $query->orWhere($column, '=', $value);
        }, Capability::EXACT);

        $this->assertCompilesTo(['name' => 'Alice', 'email' => ['$either' => 'b']], '"name" = ? and ("email" = ?)', ['Alice', 'b']);

        $this->assertCompilesTo(
            ['posts' => ['title' => ['$either' => 'x']]],
            'exists (select * from "posts" where "users"."id" = "posts"."user_id" and ("title" = ?))',
            ['x'],
        );

        $this->assertCompilesTo(
            ['$hasnt' => ['posts' => ['id' => 1, 'title' => ['$either' => 'x']]]],
            'not exists (select * from "posts" where "users"."id" = "posts"."user_id" and "id" = ? and ("title" = ?))',
            [1, 'x'],
        );
    }

    /**
     * Test that a negated OR clause is grouped on its own, keeping the bindings
     * of the conditions after it in order.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCustomOperatorJoiningWithOrNotIsGroupedOnItsOwn(): void
    {
        $this->operatorRegistry->register('$neither', static function (Builder $query, string $column, mixed $value): void {
            $query->orWhereNot($column, '=', $value);
        }, Capability::EXACT);

        $this->assertCompilesTo(
            ['name' => 'Alice', 'email' => ['$neither' => 'b', '$in' => ['c']]],
            '"name" = ? and (not "email" = ?) and "email" in (?)',
            ['Alice', 'b', 'c'],
        );
    }

    /**
     * Test that an AND-joined custom clause is left ungrouped.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCustomOperatorJoiningWithAndIsLeftUngrouped(): void
    {
        $this->operatorRegistry->register('$both', static function (Builder $query, string $column, mixed $value): void {
            $query->where($column, '=', $value)->whereNotNull($column);
        }, Capability::EXACT);

        $this->assertCompilesTo(['name' => 'Alice', 'email' => ['$both' => 'b']], '"name" = ? and "email" = ? and "email" is not null', ['Alice', 'b']);
    }

    /**
     * Test that $has and $hasnt inside an $or document attach with OR.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExistenceOperatorsInsideAnOrDocumentAttachWithOr(): void
    {
        $exists = '(select * from "posts" where "users"."id" = "posts"."user_id")';

        $this->assertCompilesTo(
            ['$or' => [['name' => 'Alice'], ['$has' => 'posts']]],
            '(("name" = ?) or (exists ' . $exists . '))',
            ['Alice'],
        );

        $this->assertCompilesTo(
            ['$or' => [['name' => 'Alice'], ['$hasnt' => ['posts']]]],
            '(("name" = ?) or (not exists ' . $exists . '))',
            ['Alice'],
        );
    }

    /**
     * Test that a structural token under a column is refused as no condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testStructuralTokenUnderAColumnIsNotACondition(): void
    {
        $this->assertRefusedAt(['name' => ['$has' => 'posts']], 'filters.name.$has', self::notACondition('filters.name.$has'));
    }

    /**
     * Test that an undeclared column is rejected.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithUndeclaredColumnIsRejected(): void
    {
        $this->assertRejectsKey(['nonexistent_column' => 'value'], 'nonexistent_column');
    }

    /**
     * Test that $notNull inside an $or document attaches its document with OR.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNotNullInsideAnOrDocument(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['organization_id' => ['$notNull' => true]], ['name' => 'Alice']]],
            '(("organization_id" is not null) or ("name" = ?))',
            ['Alice'],
        );
    }

    /**
     * Test that an undeclared key is rejected at the key, before its value.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredKeyIsRejectedBeforeDescendingIntoItsValue(): void
    {
        $this->assertRejectsKey(['ghost' => ['deeper' => 'value']], 'ghost');
    }

    /**
     * Test that an undeclared column inside a logical group is rejected.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredColumnInsideALogicalGroupIsRejected(): void
    {
        $this->assertRejectsKey(['$or' => [['name' => 'Alice'], ['nonexistent_column' => 'value']]], 'nonexistent_column');
    }

    /**
     * Test that an undeclared column in a group is rejected before its handler.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredColumnUnderAnOperatorInsideALogicalGroupIsRejected(): void
    {
        $this->assertRejectsKey(['$or' => [['nonexistent_column' => ['$eq' => 'Alice']]]], 'nonexistent_column');
    }

    /**
     * Test that an existence operator rejects an undeclared relation.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredRelationListedByAnExistenceOperatorIsRejected(): void
    {
        $this->assertRejectsKey(['$has' => ['organization']], 'organization');
    }

    /**
     * Test that a named relation with conditions must be declared traversable.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredNamedRelationUnderAnExistenceOperatorIsRejected(): void
    {
        $this->assertRejectsKey(['$has' => ['organization' => ['name' => 'Acme']]], 'organization');
    }

    /**
     * Test that a column failing the surface guard never reaches the handler.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionOperatorOnGuardedColumnIsRejected(): void
    {
        $this->assertRejectsKey(['forbidden_column' => ['$eq' => 'Alice']], 'forbidden_column');
    }

    /**
     * Test that an operator the column's capability refuses is rejected.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRefusedOperatorIsRejectedBeforeItsHandlerRuns(): void
    {
        $this->assertRejectsOperator(['name' => ['$neq' => 'Alice']], 'name', '$neq', '$eq, $in, $null, $notNull');
    }

    /**
     * Test that the bare shorthand is gated as the equality it compiles to.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testBareShorthandIsGatedAsTheEqualityItCompilesTo(): void
    {
        $this->assertRejectsOperator(['context' => 'php'], 'context', '$eq', '$contains');
    }

    /**
     * Test that a refused operator inside a logical group is rejected.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRefusedOperatorInsideALogicalGroupIsRejected(): void
    {
        $this->assertRejectsOperator(['$or' => [['id' => ['$contains' => 'php']]]], 'id', '$contains', '$eq, $in, $gt, $ge, $lt, $le, $between, $null, $notNull');
    }

    /**
     * Test that a relation's operators are gated by the related resource.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRefusedOperatorInsideARelationIsRejected(): void
    {
        $this->assertRejectsOperator(['posts' => ['title' => ['$gt' => 'a']]], 'title', '$gt', '$eq, $in, $null, $notNull');
    }

    /**
     * Test that a registered token reaches its handler on a column its
     * capabilities admit.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRegisteredTokenReachesItsHandlerOnAnAdmittedColumn(): void
    {
        $this->operatorRegistry->register('$starts', static function (Builder $query, string $column, mixed $value): void {
            $query->where($column, 'like', (is_scalar($value) ? (string) $value : '') . '%');
        }, Capability::EXACT);

        $result = $this->applyFilters(['name' => ['$starts' => 'Ali']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('like', $wheres[0]['operator']);
        self::assertSame('Ali%', $wheres[0]['value']);
    }

    /**
     * Test that a registered token is gated against the schema of each level it
     * appears at.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRegisteredTokenIsGatedAgainstEachLevelsSchema(): void
    {
        $this->operatorRegistry->register('$starts', static function (Builder $query, string $column, mixed $value): void {
            $query->where($column, 'like', (is_scalar($value) ? (string) $value : '') . '%');
        }, Capability::RANGE);

        $exists = 'exists (select * from "posts" where "users"."id" = "posts"."user_id" and "id" like ?)';

        $this->assertCompilesTo(['$or' => [['id' => ['$starts' => 1]], ['name' => 'Alice']]], '(("id" like ?) or ("name" = ?))', ['1%', 'Alice']);
        $this->assertCompilesTo(['posts' => ['id' => ['$starts' => 2]]], $exists, ['2%']);
        $this->assertCompilesTo(['$has' => ['posts' => ['id' => ['$starts' => 3]]]], $exists, ['3%']);

        $accepts = '$eq, $in, $null, $notNull';

        $this->assertRejectsOperator(['$or' => [['name' => ['$starts' => 'A']]]], 'name', '$starts', $accepts);
        $this->assertRejectsOperator(['posts' => ['title' => ['$starts' => 'a']]], 'title', '$starts', $accepts);
        $this->assertRejectsOperator(['$has' => ['posts' => ['title' => ['$starts' => 'a']]]], 'title', '$starts', $accepts);
    }

    /**
     * Test that the handler of a token the column refuses never runs.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testHandlerOfARefusedTokenNeverRuns(): void
    {
        $runs = 0;

        $this->operatorRegistry->register('$starts', static function (Builder $query, string $column) use (&$runs): void {
            $runs++;

            $query->whereNotNull($column);
        }, Capability::RANGE);

        $this->assertRejectsOperator(['name' => ['$starts' => 'A']], 'name', '$starts', '$eq, $in, $null, $notNull');

        self::assertSame(0, $runs);
    }

    /**
     * Test that the bare shorthand is refused once $eq is removed, as the token
     * itself is.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testBareShorthandIsRefusedOnceEqualityIsRemoved(): void
    {
        $this->operatorRegistry->remove('$eq');

        $this->assertRejectsOperator(['name' => 'Alice'], 'name', '$eq', '$in, $null, $notNull');
        $this->assertRefusedAt(['name' => ['$eq' => 'Alice']], 'filters.name.$eq', self::notACondition('filters.name.$eq'));
    }

    /**
     * Test that an overridden $eq serves both the bare shorthand and the token,
     * at the root and inside a group.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOverriddenEqualityServesTheShorthandAndTheToken(): void
    {
        $calls = [];

        $this->operatorRegistry->override('$eq', static function (Builder $query, string $column, mixed $value) use (&$calls): void {
            $calls[] = [$column, $value];

            $query->where($column, 'like', $value);
        });

        $this->assertCompilesTo(
            ['name' => 'a', 'email' => ['$eq' => 'b'], '$or' => [['name' => 'c'], ['email' => ['$eq' => 'd']]]],
            '"name" like ? and "email" like ? and (("name" like ?) or ("email" like ?))',
            ['a', 'b', 'c', 'd'],
        );

        self::assertSame([['name', 'a'], ['email', 'b'], ['name', 'c'], ['email', 'd']], $calls);
    }

    /**
     * Test that an overridden $eq joining its clause with OR cannot disjoin the
     * clauses beside the shorthand.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOverriddenEqualityJoiningWithOrIsGroupedUnderTheShorthand(): void
    {
        $this->operatorRegistry->override('$eq', static function (Builder $query, string $column, mixed $value): void {
            $query->orWhere($column, '=', $value);
        });

        $this->assertCompilesTo(['email' => ['$in' => ['b']], 'name' => 'Alice'], '"email" in (?) and ("name" = ?)', ['b', 'Alice']);
    }

    /**
     * Test that the bare shorthand holds an overridden $eq to the item count it
     * reports, as the token form does.
     *
     * @return void
     */
    public function testShorthandCountsTheItemsAnOverriddenEqualityExpands(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 2);

        $this->operatorRegistry->override('$eq', new ContainsOperator);

        $this->assertRejectedForCost(['name' => 'a,b,c'], QueryCostLimits::MAX_IN_ITEMS, '/name', 2, 3);
        $this->assertRejectedForCost(['$or' => [['name' => 'a,b,c']]], QueryCostLimits::MAX_IN_ITEMS, '/$or/0/name', 2, 3);
        $this->assertRejectedForCost(['name' => ['$eq' => 'a,b,c']], QueryCostLimits::MAX_IN_ITEMS, '/name/$eq', 2, 3);
    }

    /**
     * Test that the bare shorthand checks its value against the shape an
     * overridden $eq declares, at the column's position.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testShorthandChecksTheShapeAnOverriddenEqualityDeclares(): void
    {
        $this->operatorRegistry->override('$eq', new InOperator);

        $this->assertRefusedAt(['name' => 'Alice'], 'filters.name', 'The $eq condition on "name" must be a list of values.');
        $this->assertRefusedAt(['name' => ['$eq' => 'Alice']], 'filters.name.$eq', 'The $eq condition on "name" must be a list of values.');
    }

    /**
     * Test that a registered token resolving to no handler is refused.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionOperatorWithNullHandlerIsRefused(): void
    {
        // Registered but resolving to null, so has() and resolve() disagree
        $operators             = $this->getProperty($this->operatorRegistry, 'operators');
        $operators['$phantom'] = null;
        $this->setProperty($this->operatorRegistry, 'operators', $operators);

        $this->assertRefusedAt(['name' => ['$phantom' => 'Alice']], 'filters.name.$phantom', self::notACondition('filters.name.$phantom'));
    }

    /**
     * Test that STRUCTURAL_OPERATORS matches the tokens the applier dispatches.
     *
     * @return void
     */
    public function testStructuralOperatorsMatchTheDispatchMaps(): void
    {
        $logical    = $this->getProperty($this->applier, 'logicalOperatorMap');
        $relational = $this->getProperty($this->applier, 'relationalMethodMap');

        self::assertIsArray($logical);
        self::assertIsArray($relational);

        $dispatched = [...array_keys($logical), ...array_keys($relational), '$scope'];

        sort($dispatched);
        $declared = FilterApplier::STRUCTURAL_OPERATORS;
        sort($declared);

        self::assertSame($declared, $dispatched);
    }

    /**
     * Test that an undeclared filter key is rejected naming the key.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredKeyIsRejected(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT]);

        try {
            $this->applier->apply((new User)->newQuery(), ['unknown_key' => 'x'], $this->operatorRegistry, $surface);
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "unknown_key" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.unknown_key'] ?? [],
            );
        }
    }

    /**
     * Test that a declared filterable column applies a simple where clause.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredFilterableColumnIsApplied(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT]);

        $result = $this->applier->apply((new User)->newQuery(), ['name' => 'Alice'], $this->operatorRegistry, $surface);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('name', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that a declared traversable relation routes to a relation filter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredTraversableRelationIsApplied(): void
    {
        $surface = $this->declaredSurface(
            relations  : ['posts'],
            resourceMap: [Post::class => DeepTraversalPostResource::class],
        );

        $result = $this->applier->apply((new User)->newQuery(), ['posts' => ['title' => 'test']], $this->operatorRegistry, $surface);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that condition and logical operators work on a declared column.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredColumnPreservesConditionAndLogicalOperators(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT, 'email' => Capability::EXACT]);

        $eq = $this->applier->apply((new User)->newQuery(), ['name' => ['$eq' => 'Alice']], $this->operatorRegistry, $surface);

        self::assertSame('=', $eq->getQuery()->wheres[0]['operator']);

        $orFilters = ['$or' => [['name' => 'Alice'], ['email' => 'bob@example.com']]];

        $or = $this->applier->apply((new User)->newQuery(), $orFilters, $this->operatorRegistry, $surface);

        self::assertSame('select * from "users" where (("name" = ?) or ("email" = ?))', $or->toSql());
    }

    /**
     * Test that $has routes to an existence clause for a declared relation.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredRelationPreservesHasOperator(): void
    {
        $surface = $this->declaredSurface(relations: ['posts']);

        $result = $this->applier->apply((new User)->newQuery(), ['$has' => ['posts']], $this->operatorRegistry, $surface);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that a document nested to exactly the depth cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDocumentNestedToExactlyTheDepthCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 3);

        $result = $this->applyFilters([
            '$or' => [['$and' => [['$or' => [['name' => 'Alice']]]]]],
        ]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that a document one level beyond the depth cap is rejected.
     *
     * @return void
     */
    public function testDocumentNestedBeyondTheDepthCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 3);

        $this->assertRejectedForCost(
            ['$or' => [['$and' => [['$or' => [['$and' => [['name' => 'Alice']]]]]]]]],
            QueryCostLimits::MAX_DEPTH,
            '/$or/0/$and/0/$or/0/$and',
            3,
            4,
        );
    }

    /**
     * Test that a relation subquery counts as a level.
     *
     * @return void
     */
    public function testRelationTraversalCountsTowardTheDepthCap(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 1);

        $this->assertRejectedForCost(
            ['posts' => ['user' => ['name' => 'Alice']]],
            QueryCostLimits::MAX_DEPTH,
            '/posts/user',
            1,
            2,
        );
    }

    /**
     * Test that a document visiting exactly the node cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDocumentVisitingExactlyTheNodeCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $result = $this->applyFilters([
            'name'  => 'Alice',
            'email' => 'alice@example.com',
            'id'    => '1',
        ]);

        self::assertCount(3, $result->getQuery()->wheres);
    }

    /**
     * Test that a document visiting one node too many is rejected.
     *
     * @return void
     */
    public function testDocumentVisitingOneNodeTooManyIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $this->assertRejectedForCost(
            [
                'name'            => 'Alice',
                'email'           => 'alice@example.com',
                'id'              => '1',
                'organization_id' => '2',
            ],
            QueryCostLimits::MAX_NODES,
            '/organization_id',
            3,
            4,
        );
    }

    /**
     * Test that the keys inside a logical group count toward the node cap.
     *
     * @return void
     */
    public function testKeysInsideALogicalGroupCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['$or' => [['name' => 'Alice'], ['email' => 'alice@example.com']]],
            QueryCostLimits::MAX_NODES,
            '/$or/1/email',
            2,
            3,
        );
    }

    /**
     * Test that each condition under a column counts toward the node cap.
     *
     * @return void
     */
    public function testConditionsUnderAColumnCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(['id' => ['$gt' => 1, '$lt' => 5]], QueryCostLimits::MAX_NODES, '/id/$lt', 2, 3);
    }

    /**
     * Test that the keys inside a traversed relation count toward the node cap.
     *
     * @return void
     */
    public function testKeysInsideARelationCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['posts' => ['title' => 'first', 'id' => '1']],
            QueryCostLimits::MAX_NODES,
            '/posts/id',
            2,
            3,
        );
    }

    /**
     * Test that keys in a group within a relation count toward the node cap.
     *
     * @return void
     */
    public function testKeysInsideAGroupWithinARelationCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $this->assertRejectedForCost(
            ['posts' => ['$or' => [['title' => 'first'], ['id' => '1']]]],
            QueryCostLimits::MAX_NODES,
            '/posts/$or/1/id',
            3,
            4,
        );
    }

    /**
     * Test that each relation an existence operator lists counts as a node.
     *
     * @return void
     */
    public function testRelationsListedByAnExistenceOperatorCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['$has' => ['posts', 'organization']],
            QueryCostLimits::MAX_NODES,
            '/$has/1',
            2,
            3,
        );
    }

    /**
     * Test that a rejection inside an existence operator points beneath it.
     *
     * @return void
     */
    public function testRejectionInsideANamedExistenceRelationPointsAtItsPosition(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 1);

        $this->assertRejectedForCost(
            ['$has' => ['posts' => ['title' => ['$in' => ['first', 'second']]]]],
            QueryCostLimits::MAX_IN_ITEMS,
            '/$has/posts/title/$in',
            1,
            2,
        );
    }

    /**
     * Test that an operator value list of exactly the item cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOperatorValueListAtExactlyTheItemCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $result = $this->applyFilters(['name' => ['$in' => ['Alice', 'Bob', 'Carol']]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame(['Alice', 'Bob', 'Carol'], $wheres[0]['values']);
    }

    /**
     * Test that a value list one item over the cap is rejected before binding.
     *
     * @return void
     */
    public function testOperatorValueListOverTheItemCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $this->assertRejectedForCost(
            ['name' => ['$in' => ['Alice', 'Bob', 'Carol', 'Dave']]],
            QueryCostLimits::MAX_IN_ITEMS,
            '/name/$in',
            3,
            4,
        );
    }

    /**
     * Test that a delimited value list of exactly the item cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDelimitedValueListAtExactlyTheItemCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => 'php,rust,go']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertCount(3, $wheres[0]['query']->wheres);
    }

    /**
     * Test that a delimited value list one item over the cap is rejected.
     *
     * @return void
     */
    public function testDelimitedValueListOverTheItemCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $this->assertRejectedForCost(
            ['context' => [self::OPERATOR_CONTAINS => 'php,rust,go,zig']],
            QueryCostLimits::MAX_IN_ITEMS,
            '/context/$contains',
            3,
            4,
        );
    }

    /**
     * Test that a scalar operator value counts as a single item.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testScalarOperatorValueCountsAsOneItem(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 1);

        $result = $this->applyFilters(['name' => ['$eq' => 'Alice, Bob, Carol, Dave']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Alice, Bob, Carol, Dave', $wheres[0]['value']);
    }

    /**
     * Test that a rejection inside a relation points at its own position.
     *
     * @return void
     */
    public function testRejectionInsideARelationPointsAtItsPosition(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 1);

        $this->assertRejectedForCost(
            ['posts' => ['title' => ['$in' => ['first', 'second']]]],
            QueryCostLimits::MAX_IN_ITEMS,
            '/posts/title/$in',
            1,
            2,
        );
    }

    /**
     * Test that a key holding a pointer delimiter is escaped in a cost pointer.
     *
     * @return void
     */
    public function testAKeyHoldingAPointerDelimiterIsEscapedInACostPointer(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 1);

        $this->assertRejectedForCost(['name' => 'Alice', 'a/b~c' => 'x'], QueryCostLimits::MAX_NODES, '/a~1b~0c', 1, 2);
    }

    /**
     * Test that a list at the root of the walk is named against the parameter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testListAtTheRootOfTheWalkIsNamedAgainstTheParameter(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT]);

        try {
            $this->applier->apply((new User)->newQuery(), ['Alice', 'Bob'], $this->operatorRegistry, $surface);

            self::fail('A list at the root of the walk must be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['filters' => ['The filters filter must name a field or a condition. To match any of several values, give the field a condition such as $in.']],
                $exception->errors(),
            );
        }
    }

    /**
     * Test that a list given to a named field names that field.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testListGivenToANamedFieldNamesThatField(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT]);

        try {
            $this->applier->apply((new User)->newQuery(), ['name' => ['Alice', 'Bob']], $this->operatorRegistry, $surface);

            self::fail('A list given to a named field must be refused.');
        } catch (ValidationException $exception) {
            // Asserted whole: a substring match lets the halves reorder.
            self::assertSame(
                ['filters.name' => ['The filters.name filter must name a field or a condition. To match any of several values, give the field a condition such as $in.']],
                $exception->errors(),
            );
        }
    }

    /**
     * Provide filters of a refused shape, with their position and message.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function malformedDocumentProvider(): iterable
    {
        $notGroup    = static fn (string $position): string => 'The ' . $position . ' filter must be a non-empty list of filter documents.';
        $empty       = static fn (string $position): string => 'The ' . $position . ' filter must carry at least one condition.';
        $fieldless   = static fn (string $position): string => 'The ' . $position . ' condition must be given under a field.';
        $notDocument = static fn (string $position): string => 'The ' . $position . ' filter must be a document of conditions.';

        yield 'bare value under $or' => [['$or' => 'Alice'], 'filters.$or', $notGroup('filters.$or')];
        yield 'bare value under nested $or' => [['$and' => [['$or' => 'Alice']]], 'filters.$and.0.$or', $notGroup('filters.$and.0.$or')];
        yield 'object under $or' => [['$or' => ['name' => 'Alice']], 'filters.$or', $notGroup('filters.$or')];
        yield 'empty list under $and' => [['$and' => []], 'filters.$and', $notGroup('filters.$and')];
        yield 'empty document in $or' => [['$or' => [['name' => 'Alice'], []]], 'filters.$or.1', $empty('filters.$or.1')];
        yield 'scalar document in $or' => [['$or' => [['name' => 'Alice'], 'Bob']], 'filters.$or.1', $notDocument('filters.$or.1')];
        yield 'list document in $or' => [['$or' => [[['name' => 'Alice']]]], 'filters.$or.0', $notDocument('filters.$or.0')];
        yield 'condition at the root' => [['$eq' => 'Alice'], 'filters.$eq', $fieldless('filters.$eq')];
        yield 'condition in an $or document' => [['$or' => [['$eq' => 'Alice']]], 'filters.$or.0.$eq', $fieldless('filters.$or.0.$eq')];
        yield 'empty column object' => [['name' => []], 'filters.name', $empty('filters.name')];
        yield 'plain key under a column' => [['name' => ['email' => 'Alice']], 'filters.name.email', self::notACondition('filters.name.email')];
        yield '$or under a column' => [['name' => ['$or' => [['$eq' => 'Alice']]]], 'filters.name.$or', self::notACondition('filters.name.$or')];
        yield 'list index beside a condition' => [['name' => ['$eq' => 'Alice', 0 => 'Bob']], 'filters.name.0', self::notACondition('filters.name.0')];
        yield 'unknown token under a column' => [['name' => ['$like' => 'Alice']], 'filters.name.$like', self::notACondition('filters.name.$like')];
        yield 'bare value under a relation' => [['posts' => 'first'], 'filters.posts', 'The filters.posts filter must be a document of conditions.'];
        yield 'list under a relation' => [['posts' => ['first']], 'filters.posts', $notDocument('filters.posts')];
        yield 'list under a $has relation' => [['$has' => ['posts' => ['first']]], 'filters.$has.posts', $notDocument('filters.$has.posts')];
        yield 'number under $has' => [['$has' => 1], 'filters.$has', 'The filters.$has filter must be a document of conditions.'];
        yield 'empty list under $has' => [['$has' => []], 'filters.$has', self::addsNothing('filters.$has')];
        yield 'list entry under $hasnt' => [['$hasnt' => ['posts', ['posts']]], 'filters.$hasnt.1', 'The filters.$hasnt.1 filter must name a relation.'];
        yield 'bare value under a $has relation' => [['$has' => ['posts' => 'first']], 'filters.$has.posts', 'The filters.$has.posts filter must be a document of conditions.'];
        yield 'one-value $between' => [['id' => ['$between' => [1]]], 'filters.id.$between', self::BETWEEN_BOUNDS];
        yield 'one-value $between alone in an $or' => [['$or' => [['id' => ['$between' => [1]]]]], 'filters.$or.0.id.$between', self::BETWEEN_BOUNDS];
        yield '$gt beside a one-value $between' => [['id' => ['$gt' => 1, '$between' => [2]]], 'filters.id.$between', self::BETWEEN_BOUNDS];
        yield '$contains of only delimiters' => [['context' => ['$contains' => ',,']], 'filters.context.$contains', self::addsNothing('filters.context.$contains')];
    }

    /**
     * Test that a value of the wrong shape is refused at its position.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('malformedDocumentProvider')]
    public function testMalformedDocumentIsRefusedAtItsPosition(array $filters, string $position, string $message): void
    {
        $this->assertRefusedAt($filters, $position, $message);
    }

    /**
     * Provide filters of a refused shape, with their position and the German
     * message each is refused with.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function localisedMalformedDocumentProvider(): iterable
    {
        yield 'list under a field' => [['name' => ['Alice']], 'filters.name', 'Der Filter filters.name muss ein Feld benennen.'];
        yield 'bare value under a relation' => [['posts' => 'first'], 'filters.posts', 'Der Filter filters.posts muss ein Dokument sein.'];
        yield 'list entry under $has' => [['$has' => [['posts']]], 'filters.$has.0', 'Der Filter filters.$has.0 muss eine Relation benennen.'];
        yield 'object under $or' => [['$or' => ['name' => 'Alice']], 'filters.$or', 'Der Filter filters.$or muss eine Liste sein.'];
        yield 'empty column object' => [['name' => []], 'filters.name', 'Der Filter filters.name ist leer.'];
        yield 'plain key under a column' => [['name' => ['email' => 'Alice']], 'filters.name.email', 'Der Filter filters.name.email ist keine Bedingung.'];
        yield 'condition at the root' => [['$eq' => 'Alice'], 'filters.$eq', 'Die Bedingung filters.$eq braucht ein Feld.'];
        yield 'one-value $between' => [['id' => ['$between' => [1]]], 'filters.id.$between', 'Die Bedingung $between auf "id" braucht zwei Werte.'];
        yield '$contains of only delimiters' => [['context' => ['$contains' => ',,']], 'filters.context.$contains', 'Der Filter filters.context.$contains bewirkt nichts.'];
    }

    /**
     * Test that a refused shape is worded in the current locale.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('localisedMalformedDocumentProvider')]
    public function testMalformedDocumentIsRefusedInTheCurrentLocale(array $filters, string $position, string $message): void
    {
        $this->localiseToolkitMessages([
            'filters.unnamed_key'         => 'Der Filter :position muss ein Feld benennen.',
            'filters.not_document'        => 'Der Filter :position muss ein Dokument sein.',
            'filters.relation_names'      => 'Der Filter :position muss eine Relation benennen.',
            'filters.not_group'           => 'Der Filter :position muss eine Liste sein.',
            'filters.empty_document'      => 'Der Filter :position ist leer.',
            'filters.not_condition'       => 'Der Filter :position ist keine Bedingung.',
            'filters.fieldless_condition' => 'Die Bedingung :position braucht ein Feld.',
            'filters.no_effect'           => 'Der Filter :position bewirkt nichts.',
            'filters.value_bounds'        => 'Die Bedingung :operator auf ":column" braucht zwei Werte.',
        ]);

        $this->assertRefusedAt($filters, $position, $message);
    }

    /**
     * Provide filters on a pure-enum column with the devices each matches.
     *
     * @return iterable<string, array{array<string, mixed>, array<int, string>}>
     */
    public static function pureEnumFilterProvider(): iterable
    {
        yield '$eq wire name' => [['platform' => ['$eq' => 'mobile_app']], ['Phone']];
        yield 'bare wire name' => [['platform' => 'mobile_app'], ['Phone']];
        yield '$in wire names' => [['platform' => ['$in' => ['web', 'mobile_app']]], ['Browser', 'Phone']];
        yield '$neq wire name' => [['platform' => ['$neq' => 'web']], ['Phone', 'Workstation']];
        yield '$null' => [['platform' => ['$null' => true]], ['Unregistered']];
        yield '$or wire names' => [['$or' => [['platform' => ['$eq' => 'web']], ['name' => ['$eq' => 'Workstation']]]], ['Browser', 'Workstation']];
        yield 'backed enum value' => [['status' => ['$eq' => 'inactive']], ['Phone']];
        yield 'backed enum $in' => [['status' => ['$in' => ['active', 'banned']]], ['Browser']];
        yield 'plain column' => [['name' => ['$eq' => 'Phone']], ['Phone']];
    }

    /**
     * Test that a pure-enum column is filtered by its lowercase wire name.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('pureEnumFilterProvider')]
    public function testPureEnumColumnIsFilteredByItsWireName(array $filters, array $expected): void
    {
        $this->seedDevices();

        $surface = $this->declaredSurface(self::DEVICE_FILTERABLE);

        $names = $this->applier->apply((new Device)->newQuery(), $filters, $this->operatorRegistry, $surface)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame($expected, $names);
    }

    /**
     * Provide filters naming no case of a pure-enum column, with the position
     * and message each is refused with.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function unknownPureEnumNameProvider(): iterable
    {
        yield '$eq declared name' => [['platform' => ['$eq' => 'MOBILE_APP']], 'filters.platform.$eq', 'The $eq condition on "platform" ' . self::NOT_A_PLATFORM];
        yield 'bare declared name' => [['platform' => 'MOBILE_APP'], 'filters.platform', 'The $eq condition on "platform" ' . self::NOT_A_PLATFORM];
        yield '$eq unknown name' => [['platform' => ['$eq' => 'console']], 'filters.platform.$eq', 'The $eq condition on "platform" ' . self::NOT_A_PLATFORM];
        yield '$in mixed names' => [['platform' => ['$in' => ['desktop', 'console']]], 'filters.platform.$in', 'Item 1 of the $in condition on "platform" ' . self::NOT_A_PLATFORM];
        yield '$neq declared name' => [['platform' => ['$neq' => 'WEB']], 'filters.platform.$neq', 'The $neq condition on "platform" ' . self::NOT_A_PLATFORM];
    }

    /**
     * Test that a pure-enum column refuses a name that is not a wire name,
     * rather than matching nothing or everything.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('unknownPureEnumNameProvider')]
    public function testPureEnumColumnRefusesANameThatIsNotAWireName(array $filters, string $position, string $message): void
    {
        $surface = $this->declaredSurface(self::DEVICE_FILTERABLE);

        try {
            $this->applier->apply((new Device)->newQuery(), $filters, $this->operatorRegistry, $surface);

            self::fail('The filter must be refused at ' . $position . '.');
        } catch (ValidationException $exception) {
            self::assertSame([$position => [$message]], $exception->errors());
        }
    }

    /**
     * Test that a pure-enum column on a related model is filtered by wire name.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[Group('exporter')]
    public function testPureEnumColumnOnARelatedModelIsFilteredByItsWireName(): void
    {
        $this->seedDevices();

        $surface = $this->declaredSurface(relations: ['devices'], resourceMap: [Device::class => DeviceResource::class]);

        $matched = fn (array $filters): array => $this->applier->apply((new User)->newQuery(), $filters, $this->operatorRegistry, $surface)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame(['Bob'], $matched(['devices' => ['platform' => ['$eq' => 'mobile_app']]]));
        self::assertSame(['Alice', 'Bob'], $matched(['devices' => ['platform' => ['$in' => ['web', 'mobile_app']]]]));
        self::assertSame(['Alice'], $matched(['$has' => ['devices' => ['platform' => 'web']]]));

        try {
            $matched(['devices' => ['platform' => ['$eq' => 'MOBILE_APP']]]);

            self::fail('A case name must be refused on the related model.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters.devices.platform.$eq' => ['The $eq condition on "platform" ' . self::NOT_A_PLATFORM]], $exception->errors());
        }
    }

    /**
     * Provide filters on a falsy value with the users each matches.
     *
     * @return iterable<string, array{array<string, mixed>, array<int, string>}>
     */
    public static function falsyValueFilterProvider(): iterable
    {
        yield 'bare "0"' => [['name' => '0'], ['0']];
        yield '$eq "0"' => [['name' => ['$eq' => '0']], ['0']];
        yield 'bare empty string' => [['name' => ''], ['']];
        yield '$eq empty string' => [['name' => ['$eq' => '']], ['']];
        yield 'bare 0' => [['organization_id' => 0], ['0']];
        yield '$eq 0' => [['organization_id' => ['$eq' => 0]], ['0']];
        yield 'bare false' => [['organization_id' => false], ['0']];
        yield '$eq false' => [['organization_id' => ['$eq' => false]], ['0']];
        yield 'bare "0" in an $or' => [['$or' => [['name' => '0'], ['organization_id' => 1]]], ['0', '']];
        yield 'bare 0 in an $and' => [['$and' => [['organization_id' => 0]]], ['0']];
        yield 'bare "0" in a relation' => [['posts' => ['title' => '0']], ['']];
        yield 'bare "0" in a relation $or' => [['posts' => ['$or' => [['title' => '0']]]], ['']];
        yield 'bare "0" under $has' => [['$has' => ['posts' => ['title' => '0']]], ['']];
    }

    /**
     * Test that a falsy value filters the rows that hold it.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('falsyValueFilterProvider')]
    public function testFalsyValueFiltersTheRowsHoldingIt(array $filters, array $expected): void
    {
        $this->seedFalsyValueUsers();

        self::assertSame($expected, $this->matchedUserNames($filters));
    }

    /**
     * Test that null is refused as an equality value, bare or under $eq, since
     * only $null matches a missing value.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNullEqualityValueIsRefused(): void
    {
        $message = 'The $eq condition on "organization_id" ' . self::CANNOT_BE_NULL;

        $this->assertRefusedAt(['organization_id' => null], 'filters.organization_id', $message);
        $this->assertRefusedAt(['organization_id' => ['$eq' => null]], 'filters.organization_id.$eq', $message);
        $this->assertRefusedAt(['$or' => [['name' => 'Alice'], ['organization_id' => null]]], 'filters.$or.1.organization_id', $message);
    }

    /**
     * Provide operator values the query builder would flatten, truncate or
     * reject, with the position and message each is refused with.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function unshapedOperandProvider(): iterable
    {
        $scalar = 'must be a single value other than null.';

        yield '$gt null' => [['id' => ['$gt' => null]], 'filters.id.$gt', 'The $gt condition on "id" ' . self::CANNOT_BE_NULL];
        yield '$eq list' => [['name' => ['$eq' => ['Bob', 'Alice']]], 'filters.name.$eq', 'The $eq condition on "name" must be a single value.'];
        yield '$gt list' => [['id' => ['$gt' => [2, 0]]], 'filters.id.$gt', 'The $gt condition on "id" must be a single value.'];
        yield '$gt object' => [['id' => ['$gt' => ['x' => 1]]], 'filters.id.$gt', 'The $gt condition on "id" must be a single value.'];
        yield '$in scalar' => [['name' => ['$in' => 'Alice']], 'filters.name.$in', 'The $in condition on "name" must be a list of values.'];
        yield '$in nested lists' => [['name' => ['$in' => [[1, 2], [3]]]], 'filters.name.$in', 'Item 0 of the $in condition on "name" ' . $scalar];
        yield '$between nested first bound' => [['id' => ['$between' => [[1, 2], 3]]], 'filters.id.$between', 'Item 0 of the $between condition on "id" ' . $scalar];
        yield '$between nested second bound' => [['id' => ['$between' => [1, [2, 3]]]], 'filters.id.$between', 'Item 1 of the $between condition on "id" ' . $scalar];
        yield '$null false' => [['organization_id' => ['$null' => false]], 'filters.organization_id.$null', 'The $null condition on "organization_id" ' . self::TAKES_ONLY_TRUE];
        yield '$gt word on an integer' => [['id' => ['$gt' => 'abc']], 'filters.id.$gt', 'The $gt condition on "id" must be an integer.'];
        yield 'bare bool on a string' => [['name' => true], 'filters.name', 'The $eq condition on "name" must be a string or a number.'];
    }

    /**
     * Test that an operator value outside its shape or its column's type is
     * refused at its position before any clause is added.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('unshapedOperandProvider')]
    public function testOperandOutsideItsShapeOrTypeIsRefused(array $filters, string $position, string $message): void
    {
        $this->assertRefusedAt($filters, $position, $message);
    }

    /**
     * Test that a handler declaring its value's shape receives the value
     * resolved to the column's stored form.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testHandlerDeclaringAShapeReceivesTheResolvedValue(): void
    {
        $handler = new class implements DeclaresValueShape, FilterOperator {
            /** @var mixed */
            public mixed $received = null;

            /**
             * Return the shape of the value the operator accepts.
             *
             * @return \SineMacula\ApiToolkit\Enums\FilterValueShape
             */
            #[\Override]
            public function valueShape(): FilterValueShape
            {
                return FilterValueShape::SCALAR;
            }

            /**
             * Record the value and constrain the column by it.
             *
             * @template TModel of \Illuminate\Database\Eloquent\Model
             *
             * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
             * @param  string  $column
             * @param  mixed  $value
             * @return void
             */
            #[\Override]
            public function apply(Builder $query, string $column, mixed $value): void
            {
                $this->received = $value;

                $query->getQuery()->where($column, '>', $value);
            }
        };

        $this->operatorRegistry->register('$after', $handler, Capability::RANGE);

        $surface = $this->declaredSurface(['created_at' => Capability::RANGE]);

        $this->applier->apply((new User)->newQuery(), ['created_at' => ['$after' => '2024-01-01T10:00:00+02:00']], $this->operatorRegistry, $surface);

        self::assertSame('2024-01-01 08:00:00', $handler->received);

        $this->assertRefusedAt(['id' => ['$after' => null]], 'filters.id.$after', 'The $after condition on "id" ' . self::CANNOT_BE_NULL);
    }

    /**
     * Test that a closure handler receives the value as sent, since it declares
     * no shape.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testClosureHandlerReceivesTheValueAsSent(): void
    {
        $received = null;

        $this->operatorRegistry->register('$raw', static function (Builder $query, string $column, mixed $value) use (&$received): void {
            $received = $value;

            $query->getQuery()->whereNotNull($column);
        }, Capability::EXACT);

        $this->applyFilters(['name' => ['$raw' => ['x' => [null]]]]);

        self::assertSame(['x' => [null]], $received);
    }

    /**
     * Test that a list over the item cap is refused on cost before any of its
     * items is checked.
     *
     * @return void
     */
    public function testListOverTheItemCapIsRefusedBeforeItsItemsAreChecked(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 2);

        $this->assertRejectedForCost(['id' => ['$in' => [null, [1], 'abc']]], QueryCostLimits::MAX_IN_ITEMS, '/id/$in', 2, 3);
    }

    /**
     * Test that a null or empty filter document matches every row.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNullOrEmptyFilterDocumentMatchesEveryRow(): void
    {
        $this->seedFalsyValueUsers();

        self::assertSame(['0', '', 'Alice'], $this->matchedUserNames(null));
        self::assertSame(['0', '', 'Alice'], $this->matchedUserNames([]));
    }

    /**
     * Test that a relation is governed by the resource declared over it, so a
     * column only its canonical resource filters is refused beneath it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARelationIsGovernedByItsDeclaredResourceNotTheCanonicalOne(): void
    {
        $surface = new QuerySurface(
            SchemaCompiler::compile(PublicAuthorPostResource::class),
            new RelationTraversal(app(SchemaIntrospectionProvider::class), [User::class => UserResource::class]),
            new CallerAdmission(Request::create('/'), new GuardEvaluator),
            $this->operatorRegistry,
        );

        $query = $this->applier->apply((new Post)->newQuery(), ['user' => ['name' => 'Alice']], $this->operatorRegistry, $surface);

        self::assertSame('select * from "posts" where exists (select * from "users" where "posts"."user_id" = "users"."id" and "name" = ?)', $query->toSql());

        try {
            $this->applier->apply((new Post)->newQuery(), ['user' => ['email' => 'alice@example.com']], $this->operatorRegistry, $surface);
            self::fail('The email key must be refused beneath the declared public user resource.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters.email' => ['The "email" key is not a permitted query parameter for this resource.']], $exception->errors());
        }
    }

    /**
     * Provide documents reaching a relation's level by every form, with the key
     * each is refused at when the declared resource omits it.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function relationLevelProvider(): iterable
    {
        yield 'relation key' => [['posts' => ['id' => ['$gt' => 1]]], 'id'];
        yield '$has document' => [['$has' => ['posts' => ['user' => ['name' => 'Alice']]]], 'user'];
        yield '$hasnt document' => [['$hasnt' => ['posts' => ['id' => 1]]], 'id'];
        yield '$or inside a relation' => [['posts' => ['$or' => [['title' => 'First'], ['id' => 1]]]], 'id'];
        yield '$and inside an existence document' => [['$has' => ['posts' => ['$and' => [['title' => 'First'], ['user' => ['name' => 'Alice']]]]]], 'user'];
    }

    /**
     * Test that existence documents and groups inside a relation are checked
     * against the resource declared for it, not the related model's mapped one.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $key
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('relationLevelProvider')]
    public function testEveryFormInsideARelationIsCheckedAgainstThatLevelsResource(array $filters, string $key): void
    {
        $canonical = $this->declaredSurface(relations: ['posts'], resourceMap: [Post::class => DeepTraversalPostResource::class]);
        $declared  = $this->declaredSurface(relations: ['posts'], resourceMap: [Post::class => DeepTraversalPostResource::class], resources: ['posts' => PostResource::class]);

        $this->applier->apply((new User)->newQuery(), $filters, $this->operatorRegistry, $canonical);

        try {
            $this->applier->apply((new User)->newQuery(), $filters, $this->operatorRegistry, $declared);
            self::fail('The "' . $key . '" key must be refused beneath the declared post resource.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters.' . $key => ['The "' . $key . '" key is not a permitted query parameter for this resource.']], $exception->errors());
        }
    }

    /**
     * Provide polymorphic filters through whereHas, a disjoined whereHas and
     * whereDoesntHave, with the note titles each matches.
     *
     * @return iterable<string, array{array<string, mixed>, array<int, string>}>
     */
    public static function polymorphicFilterProvider(): iterable
    {
        yield 'whereHas' => [['noteable' => ['name' => 'Alice']], ['Alice note']];
        yield 'whereHas inside $or' => [['$or' => [['title' => 'Loose note'], ['noteable' => ['name' => 'Bob']]]], ['Bob note', 'Loose note']];
        yield 'whereDoesntHave' => [['$hasnt' => ['noteable' => ['name' => 'Alice']]], ['Bob note', 'Loose note']];
    }

    /**
     * Test that a polymorphic relation is filtered per type through every
     * existence method a key compiles to.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('polymorphicFilterProvider')]
    public function testAPolymorphicRelationIsFilteredByEachTypesResource(array $filters, array $expected): void
    {
        $this->seedNotes();

        self::assertSame($expected, $this->matchedNoteTitles($filters));
    }

    /**
     * Provide the polymorphic filters alone.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function polymorphicDocumentProvider(): iterable
    {
        foreach (self::polymorphicFilterProvider() as $name => [$filters]) {
            yield $name => [$filters];
        }
    }

    /**
     * Test that a polymorphic relation refuses a key the resource of a type
     * present, or the absence of one, does not permit.
     *
     * @param  array<string, mixed>  $filters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('polymorphicDocumentProvider')]
    public function testAPolymorphicRelationRefusesAKeyAnUnmappedTypeDoesNotGovern(array $filters): void
    {
        $this->seedNotes();
        $this->attachNote(Post::create(['user_id' => 1, 'title' => 'Alice', 'body' => 'Body']), 'Post note');

        try {
            $this->matchedNoteTitles($filters);
            self::fail('The name key must be refused for the unmapped post type.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters.name' => ['The "name" key is not a permitted query parameter for this resource.']], $exception->errors());
        }
    }

    /**
     * Test that with no polymorphic type stored, nested keys go unchecked, $has
     * matches nothing and $hasnt matches every row.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAPolymorphicRelationWithNoStoredTypeLeavesItsBodyUnchecked(): void
    {
        foreach (['First note', 'Second note'] as $title) {

            $note        = new Note;
            $note->title = $title;

            $note->save();
        }

        self::assertSame([], $this->matchedNoteTitles(['$has' => ['noteable' => ['secret' => 'x']]]));
        self::assertSame(['First note', 'Second note'], $this->matchedNoteTitles(['$hasnt' => ['noteable' => ['secret' => 'x']]]));
    }

    /**
     * Test that a scope inside $or attaches by OR and keeps its own OR grouped.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAScopeInsideOrAttachesByOrAndKeepsItsOwnOrGrouped(): void
    {
        $query = $this->applyToGatherings(['$or' => [['visibility' => 'private'], ['$scope' => ['near' => ['lat' => 51.5, 'lng' => -0.5, 'km' => 1]]]]]);

        self::assertSame(
            'select * from "gatherings" where (("visibility" = ?) or (("latitude" between ? and ? and "longitude" between ? and ?))) and "gatherings"."deleted_at" is null',
            $query->toSql(),
        );
        self::assertSame(['private', 51.5 - 1 / 111, 51.5 + 1 / 111, -0.5 - 1 / 111, -0.5 + 1 / 111], $query->getBindings());
    }

    /**
     * Test that a scope in a relation body or under $has resolves against the
     * resource declared over the relation.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAScopeUnderARelationResolvesAgainstTheRelatedResource(): void
    {
        $this->freezeTime();

        foreach ([['sessions' => ['$scope' => 'past']], ['$has' => ['sessions' => ['$scope' => 'past']]]] as $filters) {

            $query = $this->applyToGatherings($filters);

            // The self-join alias counts every self-join the process has built
            self::assertSame(
                'select * from "gatherings" where exists (select * from "gatherings" as "self" where "gatherings"."id" = "self"."parent_id" '
                . 'and ("starts_at" <= ?) and "self"."deleted_at" is null) and "gatherings"."deleted_at" is null',
                preg_replace('/laravel_reserved_\d+/', 'self', $query->toSql()),
            );
            self::assertEquals([now()], $query->getBindings());
        }
    }

    /**
     * Provide documents invoking scopes wrongly at each level, each with the
     * key and message of the refusal.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function misplacedScopeProvider(): iterable
    {
        yield 'undeclared at the root' => [['$scope' => 'cancelled'], 'filters.$scope.cancelled', 'The "cancelled" scope is not declared for this resource.'];

        yield 'undeclared under $or' => [
            ['$or' => [['visibility' => 'public'], ['$scope' => 'cancelled']]],
            'filters.$or.1.$scope.cancelled',
            'The "cancelled" scope is not declared for this resource.',
        ];

        yield 'declared only by the parent in a relation body' => [
            ['sessions' => ['$scope' => 'upcoming']],
            'filters.sessions.$scope.upcoming',
            'The "upcoming" scope is not declared for this resource.',
        ];

        yield 'misshapen under $or' => [
            ['$or' => [['visibility' => 'public'], ['$scope' => 5]]],
            'filters.$or.1.$scope',
            'The filters.$or.1.$scope filter must name a scope, list scope names, or map scope names to objects of arguments.',
        ];

        yield 'an undeclared argument under $or' => [
            ['$or' => [['visibility' => 'public'], ['$scope' => ['near' => ['lat' => 1, 'lng' => 1, 'km' => 1, 'unit' => 'mi']]]]],
            'filters.$or.1.$scope.near.unit',
            'The "unit" argument is not declared by the "near" scope.',
        ];

        yield 'a failing argument under $or' => [
            ['$or' => [['visibility' => 'public'], ['$scope' => ['near' => ['lat' => 1, 'lng' => 1, 'km' => 51]]]]],
            'filters.$or.1.$scope.near.km',
            'The km field must be between 1 and 50.',
        ];

        yield 'a hidden scope for a guest' => [['$scope' => 'mine'], 'filters.$scope.mine', 'The "mine" scope is not declared for this resource.'];
    }

    /**
     * Test that a scope refusal is keyed by its full position in the document.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('misplacedScopeProvider')]
    public function testAScopeRefusalIsKeyedByItsFullPosition(array $filters, string $position, string $message): void
    {
        try {
            $this->applyToGatherings($filters);

            self::fail('The filter document must be refused at ' . $position . '.');
        } catch (ValidationException $exception) {
            self::assertSame([$position => [$message]], $exception->errors());
        }
    }

    /**
     * Assert that the filters are rejected on cost with the given details.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $reason
     * @param  string  $pointer
     * @param  int  $limit
     * @param  int  $actual
     * @return void
     */
    private function assertRejectedForCost(array $filters, string $reason, string $pointer, int $limit, int $actual): void
    {
        try {
            $this->applyFilters($filters);

            self::fail('Expected a rejection for the "' . $reason . '" cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => $pointer,
                'reason'    => $reason,
                'limit'     => $limit,
                'actual'    => $actual,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Seed users owning devices on each platform, one device registering none.
     *
     * @return void
     */
    private function seedDevices(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Device::create(['user_id' => $alice->id, 'name' => 'Browser', 'platform' => Platform::WEB, 'status' => UserStatus::ACTIVE]);
        Device::create(['user_id' => $bob->id, 'name' => 'Phone', 'platform' => Platform::MOBILE_APP, 'status' => UserStatus::INACTIVE]);
        Device::create(['name' => 'Workstation', 'platform' => Platform::DESKTOP]);
        Device::create(['name' => 'Unregistered']);
    }

    /**
     * Seed users holding falsy names and organisations.
     *
     * @return void
     */
    private function seedFalsyValueUsers(): void
    {
        $zero  = User::create(['name' => '0', 'email' => 'zero@example.com', 'organization_id' => 0]);
        $empty = User::create(['name' => '', 'email' => 'empty@example.com', 'organization_id' => 1]);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        Post::create(['user_id' => $zero->id, 'title' => 'Hello', 'body' => 'B']);
        Post::create(['user_id' => $empty->id, 'title' => '0', 'body' => 'B']);
    }

    /**
     * Apply the filters to the users and return the matched names in order.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<int, string>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function matchedUserNames(?array $filters): array
    {
        /** @var array<int, string> */
        return $this->applyFilters($filters)->orderBy('id')->pluck('name')->all();
    }

    /**
     * Apply the filters to the notes and return the matched titles in order.
     *
     * Users are governed by the public user resource; posts by nothing. The
     * resource declared over the relation, which filters no name, governs no
     * type.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function matchedNoteTitles(array $filters): array
    {
        $surface = $this->declaredSurface(
            filterable : ['title' => Capability::EXACT],
            relations  : ['noteable'],
            resourceMap: [User::class => PublicUserResource::class],
            resources  : ['noteable' => PublicAuthorPostResource::class],
        );

        /** @var array<int, string> */
        return $this->applier->apply((new Note)->newQuery(), $filters, $this->operatorRegistry, $surface)->orderBy('title')->pluck('title')->all();
    }

    /**
     * Seed a note on each of two users and one attached to nothing.
     *
     * @return void
     */
    private function seedNotes(): void
    {
        foreach (['Alice', 'Bob'] as $name) {
            $this->attachNote(User::create(['name' => $name, 'email' => strtolower($name) . '@example.com']), $name . ' note');
        }

        $loose        = new Note;
        $loose->title = 'Loose note';

        $loose->save();
    }

    /**
     * Attach a note with the given title to the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $noteable
     * @param  string  $title
     * @return void
     */
    private function attachNote(Model $noteable, string $title): void
    {
        $note        = new Note;
        $note->title = $title;

        $note->noteable()->associate($noteable)->save();
    }

    /**
     * Apply the filters to the gatherings, governed by the gathering resource.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Gathering>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyToGatherings(array $filters): Builder
    {
        $surface = new QuerySurface(
            SchemaCompiler::compile(GatheringResource::class),
            new RelationTraversal(app(SchemaIntrospectionProvider::class), []),
            new CallerAdmission(Request::create('/'), new GuardEvaluator),
            $this->operatorRegistry,
        );

        return $this->applier->apply(Gathering::query(), $filters, $this->operatorRegistry, $surface);
    }

    /**
     * Build a query surface over the given declarations.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  array<string, string>  $resourceMap
     * @param  array<string, string>  $resources
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function declaredSurface(array $filterable = [], array $sortable = [], array $relations = [], array $resourceMap = [], array $resources = []): QuerySurface
    {
        return new QuerySurface(
            new CompiledSchema([], [], $filterable, $sortable, $relations, [], $resources),
            new RelationTraversal(app(SchemaIntrospectionProvider::class), $resourceMap),
            new CallerAdmission(Request::create('/'), new GuardEvaluator),
            $this->operatorRegistry,
        );
    }

    /**
     * Apply the filters and return the resulting query builder.
     *
     * @param  array<string, mixed>|null  $filters
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyFilters(?array $filters): Builder
    {
        return $this->applier->apply((new User)->newQuery(), $filters, $this->operatorRegistry, $this->surface());
    }

    /**
     * Build the query surface these mechanics tests filter against.
     *
     * One column per capability, so every dispatched operator has a column.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function surface(): QuerySurface
    {
        return $this->declaredSurface(
            filterable : [
                'name'            => Capability::EXACT,
                'email'           => Capability::EXACT,
                'id'              => Capability::RANGE,
                'organization_id' => Capability::EXACT,
                'status'          => Capability::ENUM,
                'context'         => Capability::DOCUMENT,
            ],
            relations  : ['posts'],
            resourceMap: [Post::class => DeepTraversalPostResource::class],
        );
    }

    /**
     * Assert that applying the filters refuses the operator on the column.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $column
     * @param  string  $operator
     * @param  string  $accepts
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRejectsOperator(array $filters, string $column, string $operator, string $accepts): void
    {
        $query = (new User)->newQuery();

        try {
            $this->applier->apply($query, $filters, $this->operatorRegistry, $this->surface());
            self::fail('Expected a ValidationException for the "' . $operator . '" operator on the "' . $column . '" filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "' . $operator . '" operator is not permitted on the "' . $column . '" key for this resource, which accepts ' . $accepts . '.'],
                $exception->errors()['filters.' . $column . '.' . $operator] ?? [],
            );
            self::assertEmpty($query->getQuery()->wheres);
        }
    }

    /**
     * Assert that applying the filters rejects the key with a validation error.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $key
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRejectsKey(array $filters, string $key): void
    {
        try {
            $this->applyFilters($filters);
            self::fail('Expected a ValidationException for the "' . $key . '" filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "' . $key . '" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.' . $key] ?? [],
            );
        }
    }

    /**
     * Assert that the filters compile to the given where clause and bindings.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $where
     * @param  array<int, mixed>  $bindings
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertCompilesTo(array $filters, string $where, array $bindings): void
    {
        $query = $this->applyFilters($filters);

        self::assertSame('select * from "users" where ' . $where, $query->toSql());
        self::assertSame($bindings, $query->getBindings());
    }

    /**
     * Assert that applying the filters is refused with exactly the message.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRefusedAt(array $filters, string $position, string $message): void
    {
        try {
            $this->applyFilters($filters);

            self::fail('The filter document must be refused at ' . $position . '.');
        } catch (ValidationException $exception) {
            self::assertSame([$position => [$message]], $exception->errors());
        }
    }

    /**
     * Return the message refusing a key under a column that is no condition.
     *
     * @param  string  $position
     * @return string
     */
    private static function notACondition(string $position): string
    {
        return 'The ' . $position . ' filter is not a condition. A field accepts only its registered condition tokens, such as $eq or $in.';
    }

    /**
     * Return the message refusing an entry that adds no condition.
     *
     * @param  string  $position
     * @return string
     */
    private static function addsNothing(string $position): string
    {
        return 'The ' . $position . ' filter adds no condition, so it would match every row.';
    }

    /**
     * Return the clauses a relation subquery carries, asserting each is ANDed.
     *
     * @param  \Illuminate\Database\Query\Builder  $subQuery
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function relationWheres(mixed $subQuery): Collection
    {
        $wheres = collect($subQuery->wheres);

        self::assertSame(['and'], $wheres->pluck('boolean')->unique()->values()->all(), 'Every clause beside the correlation predicate must be ANDed.');

        return $wheres;
    }
}
