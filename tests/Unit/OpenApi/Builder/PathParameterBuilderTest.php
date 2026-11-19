<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
use Tests\Fixtures\Enums\Caseless;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Account;
use Tests\Fixtures\Models\Shipment;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathBindingController;
use Tests\TestCase;

/**
 * Tests for the path parameter builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PathParameterBuilder::class)]
final class PathParameterBuilderTest extends TestCase
{
    /** The schema of a parameter nothing narrows */
    private const array STRING = ['type' => 'string'];

    /** The schema of a UUID-keyed ticket */
    private const array UUID = ['type' => 'string', 'format' => 'uuid'];

    /** @var \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry The registry the builder under test registers enums in */
    private EnumSchemaRegistry $enums;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enums = new EnumSchemaRegistry;
    }

    /**
     * Test that each parameter is a required path parameter, in route order.
     *
     * @return void
     */
    public function testListsEachParameterInRouteOrder(): void
    {
        $route = $this->router()->get('users/{user}/tickets/{ticket}', [PathBindingController::class, 'userTicket']);

        self::assertSame([
            ['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => self::UUID],
        ], $this->builder()->build($route));
    }

    /**
     * Test that a route without parameters lists none.
     *
     * @return void
     */
    public function testRouteWithoutParametersListsNone(): void
    {
        self::assertSame([], $this->builder()->build($this->router()->get('users', [PathBindingController::class, 'user'])));
    }

    /**
     * Test that an unconstrained, unbound parameter is a string.
     *
     * @return void
     */
    public function testUnconstrainedUnboundParameterIsAString(): void
    {
        self::assertSame(self::STRING, $this->schemaOf($this->router()->get('values/{value}', [PathBindingController::class, 'value'])));
    }

    /**
     * Test that a constraint wins over a model binding.
     *
     * @return void
     */
    public function testConstraintWinsOverAModelBinding(): void
    {
        $route = $this->router()->get('tickets/{ticket}', [PathBindingController::class, 'ticket'])->whereNumber('ticket');

        self::assertSame(['type' => 'integer', 'minimum' => 0], $this->schemaOf($route));
    }

    /**
     * Test that a constraint outside the portable subset falls through to the
     * binding.
     *
     * @return void
     */
    public function testRejectedConstraintFallsThroughToTheBinding(): void
    {
        $route = $this->router()->get('tickets/{ticket}', [PathBindingController::class, 'ticket'])->where('ticket', '\w+');

        self::assertSame(self::UUID, $this->schemaOf($route));
    }

    /**
     * Test that a constraint outside the portable subset on an unbound
     * parameter leaves a string.
     *
     * @return void
     */
    public function testRejectedConstraintOnAnUnboundParameterIsAString(): void
    {
        $route = $this->router()->get('values/{value}', [PathBindingController::class, 'value'])->where('value', '\w+');

        self::assertSame(self::STRING, $this->schemaOf($route));
    }

    /**
     * Test that a string-backed enum references and registers its component.
     *
     * @return void
     */
    public function testStringBackedEnumReferencesItsComponent(): void
    {
        $route = $this->router()->get('statuses/{status}', [PathBindingController::class, 'status']);

        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $this->schemaOf($route));
        self::assertSame([UserStatus::class], $this->enums->classes());
    }

    /**
     * Test that an int-backed enum, which Laravel does not bind, is a string.
     *
     * @return void
     */
    public function testIntBackedEnumIsAString(): void
    {
        self::assertSame(self::STRING, $this->schemaOf($this->router()->get('levels/{level}', [PathBindingController::class, 'level'])));
        self::assertSame([], $this->enums->classes());
    }

    /**
     * Test that a binding field reaches the route key resolver.
     *
     * @return void
     */
    public function testBindingFieldReachesTheRouteKeyResolver(): void
    {
        $route = $this->router()->get('users/{user:status}', [PathBindingController::class, 'user']);

        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $this->schemaOf($route));
    }

    /**
     * Test that trashed bindings reach the route key resolver.
     *
     * @return void
     */
    public function testTrashedBindingsReachTheRouteKeyResolver(): void
    {
        $live    = $this->router()->get('shipments/{shipment}', static fn (Shipment $shipment): Shipment => $shipment);
        $trashed = $this->router()->get('archive/{shipment}', static fn (Shipment $shipment): Shipment => $shipment)->withTrashed();

        self::assertSame(['type' => 'integer'], $this->schemaOf($live));
        self::assertSame(self::STRING, $this->schemaOf($trashed));
    }

    /**
     * Test that a snake-case parameter binds a camel-case argument.
     *
     * @return void
     */
    public function testSnakeCaseParameterBindsACamelCaseArgument(): void
    {
        $route = $this->router()->get('posts/{blog_post}', [PathBindingController::class, 'blogPost']);

        self::assertSame(['type' => 'integer'], $this->schemaOf($route));
    }

    /**
     * Test that a camel-case parameter binds the argument of its exact name.
     *
     * @return void
     */
    public function testCamelCaseParameterBindsItsExactName(): void
    {
        $route = $this->router()->get('posts/{blogPost}', [PathBindingController::class, 'blogPost']);

        self::assertSame(['type' => 'integer'], $this->schemaOf($route));
    }

    /**
     * Test that an argument naming no route parameter leaves the rest bound.
     *
     * @return void
     */
    public function testArgumentNamingNoParameterLeavesTheRestBound(): void
    {
        $route = $this->router()->get('tickets/{ticket}', static fn (User $owner, Ticket $ticket): Ticket => $ticket);

        self::assertSame(self::UUID, $this->schemaOf($route));
    }

    /**
     * Test that an explicit binder keeps the constraint but drops the binding.
     *
     * @return void
     */
    public function testExplicitBinderKeepsTheConstraintButDropsTheBinding(): void
    {
        $this->router()->bind('ticket', static fn (string $value): ?Ticket => Ticket::query()->find($value));

        $unconstrained = $this->router()->get('tickets/{ticket}', [PathBindingController::class, 'ticket']);
        $constrained   = $this->router()->get('constrained/{ticket}', [PathBindingController::class, 'ticket'])->whereNumber('ticket');

        self::assertSame(self::STRING, $this->schemaOf($unconstrained));
        self::assertSame(['type' => 'integer', 'minimum' => 0], $this->schemaOf($constrained));
    }

    /**
     * Test that a routable value that is not a model is a string.
     *
     * @return void
     */
    public function testNonModelRoutableIsAString(): void
    {
        $live    = $this->router()->get('handles/{handle}', [PathBindingController::class, 'handle']);
        $trashed = $this->router()->get('archive/{handle}', [PathBindingController::class, 'handle'])->withTrashed();

        self::assertSame(self::STRING, $this->schemaOf($live));
        self::assertSame(self::STRING, $this->schemaOf($trashed));
    }

    /**
     * Test that a model the route key resolver cannot state is a string.
     *
     * @return void
     */
    public function testUnstatableRouteKeyIsAString(): void
    {
        self::assertSame(self::STRING, $this->schemaOf($this->router()->get('invoices/{invoice}', [PathBindingController::class, 'invoice'])));
        self::assertSame(self::STRING, $this->schemaOf($this->router()->get('accounts/{account}', static fn (Account $account): Account => $account)));
    }

    /**
     * Test that a closure route binds its arguments.
     *
     * @return void
     */
    public function testClosureRouteBindsItsArguments(): void
    {
        $route = $this->router()->get('tickets/{ticket}', static fn (Ticket $ticket): Ticket => $ticket);

        self::assertSame(self::UUID, $this->schemaOf($route));
    }

    /**
     * Test that a routed method absent from its controller is a string.
     *
     * @return void
     */
    public function testMissingActionIsAString(): void
    {
        self::assertSame(self::STRING, $this->schemaOf($this->router()->get('tickets/{ticket}', [PathBindingController::class, 'missing'])));
    }

    /**
     * Test that only the URI's parameters are path parameters, a name in both
     * the domain and the URI listed once.
     *
     * @return void
     */
    public function testOnlyTheUrisParametersArePathParameters(): void
    {
        $route = $this->router()->get('users/{user}/{value?}', [PathBindingController::class, 'value'])->domain('{account}.{user}.example.com');

        self::assertSame(['user', 'value'], array_column($this->builder()->build($route), 'name'));
    }

    /**
     * Test that each domain parameter is a variable defaulting to its name, in
     * the order the domain declares them.
     *
     * @return void
     */
    public function testDomainParametersAreVariablesDefaultingToTheirName(): void
    {
        $route = $this->router()->get('projects', [PathBindingController::class, 'value'])->domain('{region}.{account?}.example.com');

        self::assertSame(
            ['region' => ['default' => 'region'], 'account' => ['default' => 'account']],
            $this->builder()->hostVariables($route),
        );
    }

    /**
     * Test that a route without a domain has no server variables.
     *
     * @return void
     */
    public function testRouteWithoutADomainHasNoVariables(): void
    {
        self::assertSame([], $this->builder()->hostVariables($this->router()->get('statuses/{status}', [PathBindingController::class, 'status'])));
    }

    /**
     * Test that a list of literals narrows a domain variable to an enum
     * defaulting to its first value, whatever the parameter binds.
     *
     * @return void
     */
    public function testLiteralConstraintNarrowsTheVariable(): void
    {
        $route = $this->router()->get('statuses/{status}', [PathBindingController::class, 'status'])
            ->domain('{account}.{status}.example.com')
            ->whereIn('account', ['acme', 'globex'])
            ->whereIn('status', ['active']);

        self::assertSame(
            ['account' => ['enum' => ['acme', 'globex'], 'default' => 'acme'], 'status' => ['enum' => ['active'], 'default' => 'active']],
            $this->builder()->hostVariables($route),
        );
    }

    /**
     * Test that a bound string-backed enum narrows a domain variable to its
     * values, without registering its component, unless a list of literals
     * constrains it.
     *
     * @return void
     */
    public function testBoundEnumNarrowsTheVariable(): void
    {
        $bound       = $this->router()->get('projects', [PathBindingController::class, 'status'])->domain('{status}.example.com');
        $constrained = $this->router()->get('reports', [PathBindingController::class, 'status'])->domain('{status}.example.com')->whereIn('status', ['banned']);
        $patterned   = $this->router()->get('audits', [PathBindingController::class, 'status'])->domain('{status}.example.com')->where('status', '[a-z]+');
        $values      = ['status' => ['enum' => ['active', 'inactive', 'banned'], 'default' => 'active']];

        self::assertSame($values, $this->builder()->hostVariables($bound));
        self::assertSame($values, $this->builder()->hostVariables($patterned));
        self::assertSame(['status' => ['enum' => ['banned'], 'default' => 'banned']], $this->builder()->hostVariables($constrained));
        self::assertSame([], $this->enums->classes());
    }

    /**
     * Provide the URI, action and constraint of a domain route whose binding or
     * constraint cannot narrow its variable.
     *
     * @return iterable<string, array{0: string, 1: array{class-string, string}|\Closure(never): mixed, 2: string|null}>
     */
    public static function unnarrowedVariableProvider(): iterable
    {
        yield 'enum bound through the uri' => ['statuses/{status}', [PathBindingController::class, 'status'], null];
        yield 'enum without cases' => ['projects', static fn (Caseless $status): string => $status->value, null];
        yield 'int-backed enum' => ['projects', static fn (UserLevel $status): int => $status->value, null];
        yield 'model' => ['projects', static fn (User $status): User => $status, null];
        yield 'pattern' => ['projects', static fn (string $status): string => $status, '[a-z]+'];
    }

    /**
     * Test that a domain variable nothing narrows defaults to its name.
     *
     * @param  string  $uri
     * @param  array{class-string, string}|\Closure(never): mixed  $action
     * @param  string|null  $constraint
     * @return void
     */
    #[DataProvider('unnarrowedVariableProvider')]
    public function testUnnarrowedVariableDefaultsToItsName(string $uri, array|\Closure $action, ?string $constraint): void
    {
        $route = $this->router()->get($uri, $action)->domain('{status}.example.com')->where('status', $constraint);

        self::assertSame(['status' => ['default' => 'status']], $this->builder()->hostVariables($route));
    }

    /**
     * Test that an explicit binder keeps a domain variable's constraint but
     * drops its enum binding.
     *
     * @return void
     */
    public function testExplicitBinderDropsTheVariablesBinding(): void
    {
        $this->router()->bind('status', static fn (string $value): UserStatus => UserStatus::from($value));

        $bound       = $this->router()->get('projects', [PathBindingController::class, 'status'])->domain('{status}.example.com');
        $constrained = $this->router()->get('reports', [PathBindingController::class, 'status'])->domain('{status}.example.com')->whereIn('status', ['banned']);

        self::assertSame(['status' => ['default' => 'status']], $this->builder()->hostVariables($bound));
        self::assertSame(['status' => ['enum' => ['banned'], 'default' => 'banned']], $this->builder()->hostVariables($constrained));
    }

    /**
     * Build the route's parameters and read the only parameter's schema.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array<string, mixed>
     */
    private function schemaOf(Route $route): array
    {
        $parameters = $this->builder()->build($route);

        self::assertCount(1, $parameters);

        return $parameters[0]['schema'];
    }

    /**
     * Build a path parameter builder reading no columns and no resources.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder
     */
    private function builder(): PathParameterBuilder
    {
        assert($this->app !== null);

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn([]);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([]);

        return new PathParameterBuilder(
            $this->router(),
            new RoutePatternTranslator,
            new RouteKeySchemaResolver($catalogue, new FieldTypeResolver($introspector, new ColumnTypeMapper, $this->enums), new RoutePatternTranslator, $this->enums, $this->app),
            $this->enums,
        );
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }
}
