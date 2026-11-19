<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ScopeApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\Scope;
use Tests\Fixtures\Models\Gathering;
use Tests\TestCase;

/**
 * Tests for the ScopeApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ScopeApplier::class)]
final class ScopeApplierTest extends TestCase
{
    /** @var string The query every case compiles around its filter clauses */
    private const string SELECT = 'select * from "gatherings" where %s and "gatherings"."deleted_at" is null';

    /** @var array<string, string> */
    private const array NEAR_RULES = ['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'km' => 'required|integer|between:1,50'];

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    /**
     * Test that a scope name, a list of names and names keyed to arguments each
     * apply every scope they name in a group of its own.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEachFormAppliesEveryScopeItNamesInAGroupOfItsOwn(): void
    {
        $this->assertCompilesTo(['$scope' => 'upcoming'], '("starts_at" > ?)', [now()]);
        $this->assertCompilesTo(['$scope' => ['upcoming', 'hasStarted']], '("starts_at" > ?) and ("starts_at" <= ?)', [now(), now()]);

        $this->assertCompilesTo(
            ['$scope' => ['near' => ['lat' => 51.5, 'lng' => -0.5, 'km' => 111], 'upcoming' => []]],
            '("latitude" between ? and ? and "longitude" between ? and ?) and ("starts_at" > ?)',
            [50.5, 52.5, -1.5, 0.5, now()],
            ['near' => ['lat' => 'numeric', 'lng' => 'numeric', 'km' => 'integer']],
        );
    }

    /**
     * Test that a scope's own OR stays inside its group, beside an AND and as a
     * branch of $or.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAScopesOwnOrStaysInsideItsGroup(): void
    {
        $named = ['named' => ['first' => 'Launch', 'second' => 'Retro']];

        $this->assertCompilesTo(
            ['visibility' => 'public', '$scope' => $named],
            '"gatherings"."visibility" = ? and (("name" = ? or "name" = ?))',
            ['public', 'Launch', 'Retro'],
        );

        $this->assertCompilesTo(
            ['$or' => [['visibility' => 'public'], ['$scope' => $named]]],
            '(("gatherings"."visibility" = ?) or ((("name" = ? or "name" = ?))))',
            ['public', 'Launch', 'Retro'],
        );
    }

    /**
     * Test that arguments bind to parameters by name whatever the order of the
     * request or the rules, on a prefixed scope and on a protected attribute
     * scope a trait declares.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testArgumentsBindToParametersByNameWhateverTheirOrder(): void
    {
        $this->assertCompilesTo(
            ['$scope' => ['named' => ['first' => 'Launch', 'second' => 'Retro']]],
            '(("name" = ? or "name" = ?))',
            ['Launch', 'Retro'],
            ['named' => ['second' => 'required|string', 'first' => 'required|string']],
        );

        $this->assertCompilesTo(
            ['$scope' => ['startingBetween' => ['until' => '2026-12-31', 'from' => '2026-01-01']]],
            '("starts_at" between ? and ?)',
            ['2026-01-01', '2026-12-31'],
            ['startingBetween' => ['until' => 'required|date', 'from' => 'required|date']],
        );
    }

    /**
     * Test that a same-named method without the scope attribute does not shadow
     * the prefixed scope.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testASameNamedPlainMethodDoesNotShadowThePrefixedScope(): void
    {
        $this->assertCompilesTo(['$scope' => 'hasStarted'], '("starts_at" <= ?)', [now()]);
    }

    /**
     * Test that a scope adding no predicate matches every row through an
     * explicit truth, so an enclosing $or keeps it.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAScopeAddingNoPredicateMatchesEveryRow(): void
    {
        $this->assertCompilesTo(
            ['$or' => [['$scope' => ['predicate' => ['case' => 'nothing']]], ['visibility' => 'public']]],
            '(((1 = 1)) or ("gatherings"."visibility" = ?))',
            ['public'],
        );
    }

    /**
     * Test that a variadic scope takes no arguments, and takes any number by
     * name in the order its rules declare them.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAVariadicScopeTakesAnyNumberOfArguments(): void
    {
        $rules = ['identified' => ['first' => 'nullable|integer', 'second' => 'nullable|integer']];

        $this->assertCompilesTo(['$scope' => ['identified' => []]], '(0 = 1)', [], $rules);
        $this->assertCompilesTo(['$scope' => ['identified' => ['second' => 4, 'first' => 2]]], '("id" in (?, ?))', [2, 4], $rules);
    }

    /**
     * Test that a null bound to a named parameter admitting it is not held to
     * the variadic parameter beside it.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testANullBoundToANullableNamedParameterIsNotHeldToTheVariadicOne(): void
    {
        $this->assertCompilesTo(
            ['$scope' => ['namedAmong' => ['name' => null, 'first' => 3]]],
            '("id" in (?))',
            [3],
            ['namedAmong' => ['name' => 'present|nullable|string', 'first' => 'integer']],
        );
    }

    /**
     * Provide filter values that are not a scope invocation.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function malformedInvocationProvider(): iterable
    {
        yield 'a number' => [5];
        yield 'an empty list' => [[]];
        yield 'a list holding a value' => [['upcoming', 5]];
        yield 'a null' => [null];
    }

    /**
     * Test that a value naming no scope is refused at the $scope position.
     *
     * @param  mixed  $value
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('malformedInvocationProvider')]
    public function testAValueNamingNoScopeIsRefused(mixed $value): void
    {
        $this->assertRefusedAt(
            ['$scope' => $value],
            'filters.$scope',
            'The filters.$scope filter must name a scope, list scope names, or map scope names to objects of arguments.',
        );
    }

    /**
     * Provide argument values that are not an object of named arguments.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function malformedArgumentsProvider(): iterable
    {
        yield 'a positional list' => [[51.5, -0.12, 5]];
        yield 'a string' => ['51.5,-0.12'];
    }

    /**
     * Test that arguments not given as an object are refused at the scope.
     *
     * @param  mixed  $arguments
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('malformedArgumentsProvider')]
    public function testArgumentsNotGivenAsAnObjectAreRefused(mixed $arguments): void
    {
        $this->assertRefusedAt(['$scope' => ['near' => $arguments]], 'filters.$scope.near', 'The "near" scope takes its arguments as an object keyed by argument name.');
    }

    /**
     * Test that an undeclared scope is refused at its name.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnUndeclaredScopeIsRefusedAtItsName(): void
    {
        $this->assertRefusedAt(['$scope' => ['upcoming', 'cancelled']], 'filters.$scope.cancelled', 'The "cancelled" scope is not declared for this resource.');
        $this->assertRefusedAt(['$scope' => ['5']], 'filters.$scope.5', 'The "5" scope is not declared for this resource.');
    }

    /**
     * Test that a scope hidden from the caller answers as an undeclared one.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAScopeHiddenFromTheCallerAnswersAsAnUndeclaredOne(): void
    {
        $hidden = new CompiledScopeDefinition('mine', 'ownedByCaller', callerGuards: [static fn (): bool => false]);

        try {
            $this->apply(['$scope' => 'mine'], $this->surface([$hidden]));

            self::fail('A hidden scope must be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters.$scope.mine' => ['The "mine" scope is not declared for this resource.']], $exception->errors());
        }
    }

    /**
     * Test that an argument the scope does not declare is refused at it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnUndeclaredArgumentIsRefusedAtIt(): void
    {
        $this->assertRefusedAt(
            ['$scope' => ['near' => ['lat' => 51.5, 'lng' => -0.12, 'km' => 5, 'radius' => 5]]],
            'filters.$scope.near.radius',
            'The "radius" argument is not declared by the "near" scope.',
        );
    }

    /**
     * Test that an argument failing its rules is refused under the scope.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnArgumentFailingItsRulesIsRefusedUnderTheScope(): void
    {
        $this->assertRefusedAt(
            ['$scope' => ['near' => ['lat' => 91, 'lng' => -0.12, 'km' => 5]]],
            'filters.$scope.near.lat',
            'The lat field must be between -90 and 90.',
        );
    }

    /**
     * Test that a validation failure the scope raises is keyed under it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAValidationFailureTheScopeRaisesIsKeyedUnderIt(): void
    {
        $this->assertRefusedAt(['$scope' => ['predicate' => ['case' => 'refuse']]], 'filters.$scope.predicate.case', 'Not on this date.');
    }

    /**
     * Test that a required parameter a rule lets go unfilled is refused.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARequiredParameterARuleLetsGoUnfilledIsRefused(): void
    {
        $this->assertRefusedAt(
            ['$scope' => ['near' => ['lat' => 51.5, 'lng' => -0.12]]],
            'filters.$scope.near.km',
            'The "km" argument is required by the "near" scope.',
            ['near' => ['lat' => 'required|numeric', 'lng' => 'required|numeric', 'km' => 'sometimes|required|integer']],
        );
    }

    /**
     * Test that a null bound to a parameter refusing null is refused.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testANullBoundToAParameterRefusingNullIsRefused(): void
    {
        $this->assertRefusedAt(
            ['$scope' => ['near' => ['lat' => null, 'lng' => -0.12, 'km' => 5]]],
            'filters.$scope.near.lat',
            'The "lat" argument of the "near" scope cannot be null.',
            ['near' => ['lat' => 'present|nullable', 'lng' => 'required|numeric', 'km' => 'required|integer']],
        );
    }

    /**
     * Test that a variadic parameter refusing null refuses it under any name.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAVariadicParameterRefusingNullRefusesItUnderAnyName(): void
    {
        $this->assertRefusedAt(
            ['$scope' => ['identified' => ['first' => 2, 'second' => null]]],
            'filters.$scope.identified.second',
            'The "second" argument of the "identified" scope cannot be null.',
            ['identified' => ['first' => 'nullable|integer', 'second' => 'nullable|integer']],
        );
    }

    /**
     * Test that a scope defined nowhere on the model is a declaration defect.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAScopeTheModelDoesNotDefineIsADeclarationDefect(): void
    {
        $this->expectExceptionObject(new \LogicException('The "archived" scope is not defined on ' . Gathering::class . '.'));

        $this->apply(['$scope' => 'archived'], $this->surface([new CompiledScopeDefinition('archived', 'archived')]));
    }

    /**
     * Test that each invoked scope counts toward the node cap.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testEachInvokedScopeCountsTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        try {
            $this->apply(['$scope' => ['upcoming', 'hasStarted']], $this->surface());

            self::fail('The second scope must exceed the node cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame(
                ['parameter' => 'filters', 'pointer' => '/$scope/hasStarted', 'reason' => QueryCostLimits::MAX_NODES, 'limit' => 2, 'actual' => 3],
                $exception->getCustomMeta(),
            );
        }
    }

    /**
     * Provide the builder calls that reach past a scope's where clauses, each
     * with how the refusal names it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function overreachingScopeProvider(): iterable
    {
        yield 'trashed rows' => ['withTrashed', 'removed global scopes'];
        yield 'an eager load' => ['with', 'added eager loads'];
        yield 'an order' => ['orderBy', 'changed the query\'s orders'];
        yield 'a join' => ['join', 'changed the query\'s joins'];
        yield 'a projection' => ['select', 'changed the query\'s columns'];
        yield 'another table' => ['from', 'changed the query\'s from'];
        yield 'a limit' => ['limit', 'changed the query\'s limit'];
        yield 'a foreign builder' => ['foreign', 'returned another builder'];
    }

    /**
     * Test that a scope reaching past its where clauses is refused and leaves
     * the query it was applied to unchanged.
     *
     * @param  string  $case
     * @param  string  $violation
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('overreachingScopeProvider')]
    public function testAScopeReachingPastItsWhereClausesIsRefused(string $case, string $violation): void
    {
        $query = Gathering::query();

        try {
            $this->apply(['$scope' => ['overreach' => ['case' => $case]]], $this->surface(), $query);

            self::fail('The scope must be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('The "overreach" scope on ' . Gathering::class . ' may only add where clauses, but it ' . $violation . '.', $exception->getMessage());
        }

        self::assertSame([], $query->getQuery()->wheres);
        self::assertSame([], $query->getEagerLoads());
        self::assertSame([], $query->removedScopes());
    }

    /**
     * Provide predicate-only builder calls a scope may make, each with the
     * clause and bindings it compiles to.
     *
     * @return iterable<string, array{string, string, array<int, mixed>}>
     */
    public static function predicateScopeProvider(): iterable
    {
        yield 'a relation existence' => ['whereHas', '(exists (select * from "users" where "gatherings"."user_id" = "users"."id" and "status" = ?))', ['active']];
        yield 'a subquery membership' => ['whereInSubquery', '("user_id" in (select "id" from "users" where "status" = ?))', ['active']];
        yield 'a correlated exists' => ['whereExists', '(exists (select * from "users" where "users"."id" = "gatherings"."user_id"))', []];
        yield 'a raw clause' => ['whereRaw', '(latitude > ?)', [10]];
        yield 'a conditional clause' => ['when', '("visibility" = ?)', ['public']];
    }

    /**
     * Test that a scope adding only predicates applies them in its group.
     *
     * @param  string  $case
     * @param  string  $where
     * @param  array<int, mixed>  $bindings
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('predicateScopeProvider')]
    public function testAScopeAddingOnlyPredicatesAppliesThem(string $case, string $where, array $bindings): void
    {
        $this->assertCompilesTo(['$scope' => ['predicate' => ['case' => $case]]], $where, $bindings);
    }

    /**
     * Assert that the filters compile to the given where clause and bindings.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $where
     * @param  array<int, mixed>  $bindings
     * @param  array<string, array<string, mixed>>  $rules
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertCompilesTo(array $filters, string $where, array $bindings, array $rules = []): void
    {
        $query = $this->apply($filters, $this->surface($this->scopes($rules)));

        self::assertSame(sprintf(self::SELECT, $where), $query->toSql());
        self::assertEquals($bindings, $query->getBindings());
    }

    /**
     * Assert that applying the filters is refused with exactly the message.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $position
     * @param  string  $message
     * @param  array<string, array<string, mixed>>  $rules
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRefusedAt(array $filters, string $position, string $message, array $rules = []): void
    {
        try {
            $this->apply($filters, $this->surface($this->scopes($rules)));

            self::fail('The filter document must be refused at ' . $position . '.');
        } catch (ValidationException $exception) {
            self::assertSame([$position => [$message]], $exception->errors());
        }
    }

    /**
     * Apply the filters to the query, or to a fresh gathering query.
     *
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Gathering>|null  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\Gathering>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function apply(array $filters, QuerySurface $surface, ?Builder $query = null): Builder
    {
        $applier = new FilterApplier(new FilterValueGuard(new ColumnLiteral(app(SchemaIntrospectionProvider::class))), new ScopeApplier);

        return $applier->apply($query ?? Gathering::query(), $filters, app(OperatorRegistry::class), $surface);
    }

    /**
     * Declare each scope of the gathering model, under the given rules where
     * given and the near scope's own otherwise.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<\SineMacula\ApiToolkit\Schema\CompiledScopeDefinition>
     */
    private function scopes(array $rules = []): array
    {
        $declared = [
            'upcoming'        => [],
            'hasStarted'      => [],
            'near'            => self::NEAR_RULES,
            'named'           => ['first' => 'required|string', 'second' => 'required|string'],
            'startingBetween' => ['from' => 'required|date', 'until' => 'required|date'],
            'identified'      => [],
            'overreach'       => ['case' => 'required|string'],
            'predicate'       => ['case' => 'required|string'],
        ];

        $scopes = [];

        foreach ([...$declared, ...$rules] as $name => $parameters) {
            $scopes[] = new CompiledScopeDefinition($name, $name, $parameters, Scope::argumentsOf($parameters));
        }

        return $scopes;
    }

    /**
     * Build a query surface filtering visibility and declaring the scopes.
     *
     * @param  list<\SineMacula\ApiToolkit\Schema\CompiledScopeDefinition>  $scopes
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function surface(array $scopes = []): QuerySurface
    {
        $keyed = [];

        foreach ($scopes === [] ? $this->scopes() : $scopes as $scope) {
            $keyed[$scope->name] = $scope;
        }

        return new QuerySurface(
            new CompiledSchema([], filterableColumns: ['visibility' => Capability::EXACT], scopes: $keyed),
            new RelationTraversal(app(SchemaIntrospectionProvider::class), []),
            new CallerAdmission(Request::create('/'), new GuardEvaluator),
            app(OperatorRegistry::class),
        );
    }
}
