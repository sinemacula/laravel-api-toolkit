<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
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
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn([]);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([]);

        return new PathParameterBuilder(
            $this->router(),
            new RoutePatternTranslator,
            new RouteKeySchemaResolver($catalogue, new FieldTypeResolver($introspector, new ColumnTypeMapper, $this->enums), new RoutePatternTranslator, $this->enums),
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
