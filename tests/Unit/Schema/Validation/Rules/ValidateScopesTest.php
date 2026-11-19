<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Schema\Scope;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateScopes;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Resources\GatheringResource;

/**
 * Tests for the ValidateScopes validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateScopes::class)]
final class ValidateScopesTest extends TestCase
{
    /**
     * Provide scopes that bind to their method, each with its rules.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function bindingScopeProvider(): iterable
    {
        yield 'a prefixed scope taking nothing' => ['upcoming', []];
        yield 'a protected attribute scope' => ['near', ['lat' => 'numeric', 'lng' => 'numeric', 'km' => 'integer']];
        yield 'a trait attribute scope' => ['startingBetween', ['from' => 'date', 'until' => 'date']];
        yield 'a variadic scope under any names' => ['identified', ['first' => 'integer', 'second' => 'integer']];
        yield 'a variadic scope under no names' => ['identified', []];
        yield 'a list argument and its item rules' => ['identified', ['ids' => 'array', 'ids.*' => 'integer']];
        yield 'every required parameter, by any key' => ['named', ['second' => 'string', 'first' => 'string']];
    }

    /**
     * Test that a scope binding to its method reports nothing.
     *
     * @param  string  $scope
     * @param  array<string, mixed>  $rules
     * @return void
     */
    #[DataProvider('bindingScopeProvider')]
    public function testAScopeBindingToItsMethodReportsNothing(string $scope, array $rules): void
    {
        self::assertSame([], $this->defects($scope, $rules));
    }

    /**
     * Test that a scope the model does not define is reported, aliased or not.
     *
     * @return void
     */
    public function testAScopeTheModelDoesNotDefineIsReported(): void
    {
        self::assertSame(['Scope "cancelled" is not a local scope of model "' . Gathering::class . '"'], $this->defects('cancelled'));
        self::assertSame(['Scope "user" is not a local scope of model "' . Gathering::class . '"'], $this->defects('user', alias: 'owner'));
    }

    /**
     * Test that an argument naming no parameter of the method is reported.
     *
     * @return void
     */
    public function testAnArgumentNamingNoParameterIsReported(): void
    {
        self::assertSame(
            ['Argument "radius" names no parameter of method "near"'],
            $this->defects('near', ['lat' => 'numeric', 'lng' => 'numeric', 'km' => 'integer', 'radius' => 'integer']),
        );
    }

    /**
     * Test that an argument naming the builder parameter is reported, on a
     * variadic method too.
     *
     * @return void
     */
    public function testAnArgumentNamingTheBuilderParameterIsReported(): void
    {
        self::assertSame(['Argument "query" names the builder parameter of method "scopeUpcoming"'], $this->defects('upcoming', ['query' => 'string']));
        self::assertSame(['Argument "query" names the builder parameter of method "scopeIdentified"'], $this->defects('identified', ['query' => 'integer']));
    }

    /**
     * Test that a required parameter no argument declares is reported.
     *
     * @return void
     */
    public function testARequiredParameterNoArgumentDeclaresIsReported(): void
    {
        self::assertSame(
            [
                'Parameter "lng" of method "near" has no default and is not a declared argument',
                'Parameter "km" of method "near" has no default and is not a declared argument',
            ],
            $this->defects('near', ['lat' => 'numeric']),
        );
    }

    /**
     * Test that every scope of the schema is checked and its defects keyed by
     * the scope's schema key, while a schema with no model reports nothing.
     *
     * @return void
     */
    public function testEveryScopeIsCheckedAndKeyedBySchemaKey(): void
    {
        $schema = new CompiledSchema([], scopes: [
            'cancelled' => new CompiledScopeDefinition('cancelled', 'cancelled'),
            'gone'      => new CompiledScopeDefinition('gone', 'archived'),
        ]);

        $errors = (new ValidateScopes)->validate(GatheringResource::class, Gathering::class, $schema);

        self::assertSame(
            [
                [GatheringResource::class, '__scope__:cancelled', 'Scope "cancelled" is not a local scope of model "' . Gathering::class . '"'],
                [GatheringResource::class, '__scope__:gone', 'Scope "archived" is not a local scope of model "' . Gathering::class . '"'],
            ],
            array_map(static fn (SchemaValidationError $error): array => [$error->resourceClass, $error->fieldKey, $error->defect], $errors),
        );

        self::assertSame([], (new ValidateScopes)->validate(GatheringResource::class, null, $schema));
        self::assertSame([], (new ValidateScopes)->validate(GatheringResource::class, \stdClass::class, $schema));
    }

    /**
     * Return the defects reported for one scope of the gathering model.
     *
     * @param  string  $scope
     * @param  array<string, mixed>  $rules
     * @param  string|null  $alias
     * @return list<string>
     */
    private function defects(string $scope, array $rules = [], ?string $alias = null): array
    {
        $name   = $alias ?? $scope;
        $schema = new CompiledSchema([], scopes: [$name => new CompiledScopeDefinition($name, $scope, $rules, Scope::argumentsOf($rules))]);

        return array_values(array_map(
            static fn (SchemaValidationError $error): string => $error->defect,
            (new ValidateScopes)->validate(GatheringResource::class, Gathering::class, $schema),
        ));
    }
}
