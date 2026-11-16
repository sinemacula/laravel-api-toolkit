<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\BetweenOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\InOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Device;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\DeepTraversalPostResource;
use Tests\Fixtures\Resources\DeviceResource;
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

        $this->operatorRegistry = new OperatorRegistry;
        $this->operatorRegistry->register('$eq', new EqualOperator);
        $this->operatorRegistry->register('$neq', new NotEqualOperator);
        $this->operatorRegistry->register('$gt', new GreaterThanOperator);
        $this->operatorRegistry->register('$lt', new LessThanOperator);
        $this->operatorRegistry->register('$ge', new GreaterThanOrEqualOperator);
        $this->operatorRegistry->register('$le', new LessThanOrEqualOperator);
        $this->operatorRegistry->register('$in', new InOperator);
        $this->operatorRegistry->register('$between', new BetweenOperator);
        $this->operatorRegistry->register('$contains', new ContainsOperator);
        $this->operatorRegistry->register('$null', new NullOperator);
        $this->operatorRegistry->register('$notNull', new NotNullOperator);

        $this->applier = new FilterApplier;
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
        $this->assertRefusedAt(['id' => ['$between' => [1]]], 'filters.id.$between', self::addsNothing('filters.id.$between'));
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
        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => 'php,rust']]);

        self::assertNotEmpty($result->getQuery()->wheres);
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
     * Test that $has dispatches structurally even when registered as an
     * operator, and is refused as a column condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testHasFilterIsNotReappliedAsColumnConditionWhenRegistered(): void
    {
        $this->operatorRegistry->register('$has', new EqualOperator);

        $wheres = $this->applyFilters(['$has' => 'posts'])->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Exists', $wheres[0]['type']);

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
     * Test that a token outside the capability matrix reaches its handler.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testTokenTheMatrixDoesNotGovernReachesItsHandler(): void
    {
        $this->operatorRegistry->register('$starts', static function (Builder $query, string $column, mixed $value): void {
            $query->where($column, 'like', (is_scalar($value) ? (string) $value : '') . '%');
        });

        $result = $this->applyFilters(['name' => ['$starts' => 'Ali']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('like', $wheres[0]['operator']);
        self::assertSame('Ali%', $wheres[0]['value']);
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

        $dispatched = array_merge(array_keys($logical), array_keys($relational));

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
        $notGroup  = static fn (string $position): string => 'The ' . $position . ' filter must be a non-empty list of filter documents.';
        $empty     = static fn (string $position): string => 'The ' . $position . ' filter must carry at least one condition.';
        $fieldless = static fn (string $position): string => 'The ' . $position . ' condition must be given under a field.';

        yield 'bare value under $or' => [['$or' => 'Alice'], 'filters.$or', $notGroup('filters.$or')];
        yield 'bare value under nested $or' => [['$and' => [['$or' => 'Alice']]], 'filters.$and.0.$or', $notGroup('filters.$and.0.$or')];
        yield 'object under $or' => [['$or' => ['name' => 'Alice']], 'filters.$or', $notGroup('filters.$or')];
        yield 'empty list under $and' => [['$and' => []], 'filters.$and', $notGroup('filters.$and')];
        yield 'empty document in $or' => [['$or' => [['name' => 'Alice'], []]], 'filters.$or.1', $empty('filters.$or.1')];
        yield 'scalar document in $or' => [['$or' => [['name' => 'Alice'], 'Bob']], 'filters.$or.1', $empty('filters.$or.1')];
        yield 'condition at the root' => [['$eq' => 'Alice'], 'filters.$eq', $fieldless('filters.$eq')];
        yield 'condition in an $or document' => [['$or' => [['$eq' => 'Alice']]], 'filters.$or.0.$eq', $fieldless('filters.$or.0.$eq')];
        yield 'empty column object' => [['name' => []], 'filters.name', $empty('filters.name')];
        yield 'plain key under a column' => [['name' => ['email' => 'Alice']], 'filters.name.email', self::notACondition('filters.name.email')];
        yield '$or under a column' => [['name' => ['$or' => [['$eq' => 'Alice']]]], 'filters.name.$or', self::notACondition('filters.name.$or')];
        yield 'list index beside a condition' => [['name' => ['$eq' => 'Alice', 0 => 'Bob']], 'filters.name.0', self::notACondition('filters.name.0')];
        yield 'unknown token under a column' => [['name' => ['$like' => 'Alice']], 'filters.name.$like', self::notACondition('filters.name.$like')];
        yield 'bare value under a relation' => [['posts' => 'first'], 'filters.posts', 'The filters.posts filter must be a document of conditions.'];
        yield 'list under a relation' => [
            ['posts' => ['first']],
            'filters.posts',
            'The filters.posts filter must name a field or a condition. To match any of several values, give the field a condition such as $in.',
        ];
        yield 'number under $has' => [['$has' => 1], 'filters.$has', 'The filters.$has filter must be a document of conditions.'];
        yield 'empty list under $has' => [['$has' => []], 'filters.$has', self::addsNothing('filters.$has')];
        yield 'list entry under $hasnt' => [['$hasnt' => [['posts']]], 'filters.$hasnt', 'The filters.$hasnt filter must list relation names.'];
        yield 'bare value under a $has relation' => [['$has' => ['posts' => 'first']], 'filters.$has.posts', 'The filters.$has.posts filter must be a document of conditions.'];
        yield 'one-value $between' => [['id' => ['$between' => [1]]], 'filters.id.$between', self::addsNothing('filters.id.$between')];
        yield 'one-value $between alone in an $or' => [['$or' => [['id' => ['$between' => [1]]]]], 'filters.$or.0.id.$between', self::addsNothing('filters.$or.0.id.$between')];
        yield '$gt beside a one-value $between' => [['id' => ['$gt' => 1, '$between' => [2]]], 'filters.id.$between', self::addsNothing('filters.id.$between')];
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
        yield 'list entry under $has' => [['$has' => [['posts']]], 'filters.$has', 'Der Filter filters.$has muss Relationen auflisten.'];
        yield 'object under $or' => [['$or' => ['name' => 'Alice']], 'filters.$or', 'Der Filter filters.$or muss eine Liste sein.'];
        yield 'empty column object' => [['name' => []], 'filters.name', 'Der Filter filters.name ist leer.'];
        yield 'plain key under a column' => [['name' => ['email' => 'Alice']], 'filters.name.email', 'Der Filter filters.name.email ist keine Bedingung.'];
        yield 'condition at the root' => [['$eq' => 'Alice'], 'filters.$eq', 'Die Bedingung filters.$eq braucht ein Feld.'];
        yield 'one-value $between' => [['id' => ['$between' => [1]]], 'filters.id.$between', 'Der Filter filters.id.$between bewirkt nichts.'];
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
            'filters.relation_names'      => 'Der Filter :position muss Relationen auflisten.',
            'filters.not_group'           => 'Der Filter :position muss eine Liste sein.',
            'filters.empty_document'      => 'Der Filter :position ist leer.',
            'filters.not_condition'       => 'Der Filter :position ist keine Bedingung.',
            'filters.fieldless_condition' => 'Die Bedingung :position braucht ein Feld.',
            'filters.no_effect'           => 'Der Filter :position bewirkt nichts.',
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
        yield '$eq declared name' => [['platform' => ['$eq' => 'MOBILE_APP']], []];
        yield 'bare declared name' => [['platform' => 'MOBILE_APP'], []];
        yield '$eq unknown name' => [['platform' => ['$eq' => 'console']], []];
        yield '$in mixed names' => [['platform' => ['$in' => ['MOBILE_APP', 'console', 'desktop']]], ['Workstation']];
        yield '$neq declared name' => [['platform' => ['$neq' => 'WEB']], ['Browser', 'Phone', 'Workstation']];
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

        $surface = new QuerySurface(self::DEVICE_FILTERABLE, [], [], new Device);

        $names = $this->applier->apply((new Device)->newQuery(), $filters, $this->operatorRegistry, $surface)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame($expected, $names);
    }

    /**
     * Test that a pure-enum column on a related model is filtered by wire name.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
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
        self::assertSame([], $matched(['devices' => ['platform' => ['$eq' => 'MOBILE_APP']]]));
        self::assertSame(['Alice'], $matched(['$has' => ['devices' => ['platform' => 'web']]]));
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
        yield 'bare null' => [['organization_id' => null], ['Alice']];
        yield '$eq null' => [['organization_id' => ['$eq' => null]], ['Alice']];
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
     * Build a query surface for the User root model.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function declaredSurface(array $filterable = [], array $sortable = [], array $relations = [], array $resourceMap = []): QuerySurface
    {
        return new QuerySurface($filterable, $sortable, $relations, new User, $resourceMap);
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
