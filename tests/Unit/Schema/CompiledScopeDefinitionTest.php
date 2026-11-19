<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use Tests\Fixtures\Models\Gathering;

/**
 * Tests for the CompiledScopeDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledScopeDefinition::class)]
final class CompiledScopeDefinitionTest extends TestCase
{
    /**
     * Test that a raw definition compiles under its client name, with the
     * arguments its rules declare and only its callable caller guards.
     *
     * @return void
     */
    public function testARawDefinitionCompilesUnderItsClientName(): void
    {
        $guard = static fn (): bool => true;
        $rules = ['lat' => 'numeric', 'ids.*' => 'integer'];

        self::assertEquals(
            new CompiledScopeDefinition('within', 'near', $rules, ['lat', 'ids'], 'Near the point.', [$guard]),
            CompiledScopeDefinition::fromRaw('__scope__:ignored', [
                'scope'         => 'near',
                'key'           => 'within',
                'parameters'    => $rules,
                'description'   => 'Near the point.',
                'caller_guards' => ['first' => 'not callable', 'second' => $guard],
            ]),
        );
    }

    /**
     * Test that a bare raw definition takes its name from its schema key and
     * runs the scope of that name.
     *
     * @return void
     */
    public function testABareRawDefinitionTakesItsNameFromItsSchemaKey(): void
    {
        self::assertEquals(
            new CompiledScopeDefinition('legacy', 'legacy'),
            CompiledScopeDefinition::fromRaw('__scope__:legacy', ['parameters' => 'lat', 'description' => 5, 'caller_guards' => 'none', 'scope' => 5]),
        );
    }

    /**
     * Test that a prefixed scope resolves to its prefixed method.
     *
     * @return void
     */
    public function testAPrefixedScopeResolvesToItsPrefixedMethod(): void
    {
        self::assertSame('scopeUpcoming', $this->methodName('upcoming', Gathering::class));
    }

    /**
     * Test that a protected attribute scope a trait declares resolves to the
     * same-named method.
     *
     * @return void
     */
    public function testAProtectedAttributeScopeResolvesToTheSameNamedMethod(): void
    {
        self::assertSame('startingBetween', $this->methodName('startingBetween', Gathering::class));
        self::assertSame('near', $this->methodName('near', Gathering::class));
    }

    /**
     * Test that a same-named method without the attribute leaves the prefixed
     * scope to answer.
     *
     * @return void
     */
    public function testASameNamedPlainMethodLeavesThePrefixedScopeToAnswer(): void
    {
        self::assertSame('scopeHasStarted', $this->methodName('hasStarted', Gathering::class));
    }

    /**
     * Test that a private attribute method leaves the prefixed scope to answer,
     * as the model cannot call it.
     *
     * @return void
     */
    public function testAPrivateAttributeMethodLeavesThePrefixedScopeToAnswer(): void
    {
        $model = new class extends Model {
            /**
             * Scope the query to archived rows, out of the model's reach.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @return void
             *
             * @phpstan-ignore method.unused, sineMaculaLaravel.modelBehaviour
             */
            #[Scope]
            private function archived(Builder $query): void
            {
                $query->whereNotNull('archived_at');
            }

            /**
             * Scope the query to archived rows.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @return void
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function scopeArchived(Builder $query): void
            {
                $query->whereNotNull('archived_at');
            }
        };

        self::assertSame('scopeArchived', $this->methodName('archived', $model::class));
    }

    /**
     * Test that a scope the model does not define resolves to nothing.
     *
     * @return void
     */
    public function testAScopeTheModelDoesNotDefineResolvesToNothing(): void
    {
        self::assertNull((new CompiledScopeDefinition('cancelled', 'cancelled'))->method(Gathering::class));
        self::assertNull((new CompiledScopeDefinition('user', 'user'))->method(Gathering::class));
    }

    /**
     * Resolve the name of the method the model runs the scope through.
     *
     * @param  string  $scope
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return string|null
     */
    private function methodName(string $scope, string $modelClass): ?string
    {
        return (new CompiledScopeDefinition($scope, $scope))->method($modelClass)?->getName();
    }
}
