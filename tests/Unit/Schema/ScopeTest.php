<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Scope;

/**
 * Tests for the Scope schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Scope::class)]
final class ScopeTest extends TestCase
{
    /**
     * Test that of keys the definition by the scope's own name.
     *
     * @return void
     */
    public function testOfKeysTheDefinitionByTheScopesOwnName(): void
    {
        self::assertSame(['__scope__:upcoming' => ['scope' => 'upcoming', 'key' => 'upcoming', 'parameters' => []]], Scope::of('upcoming')->toArray());
    }

    /**
     * Test that of with an alias keys the definition by the alias and keeps the
     * scope it runs.
     *
     * @return void
     */
    public function testOfWithAnAliasKeysTheDefinitionByTheAlias(): void
    {
        self::assertSame(['__scope__:mine' => ['scope' => 'ownedByCaller', 'key' => 'mine', 'parameters' => []]], Scope::of('ownedByCaller', 'mine')->toArray());
    }

    /**
     * Provide names a client cannot invoke a scope by.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'empty' => ['', null];
        yield 'leading digit' => ['1st', null];
        yield 'leading score' => ['_hidden', null];
        yield 'hyphen' => ['up-coming', null];
        yield 'dollar' => ['$scope', null];
        yield 'trailing newline' => ["upcoming\n", null];
        yield 'invalid alias' => ['upcoming', 'up coming'];
    }

    /**
     * Test that a name a client cannot invoke is refused.
     *
     * @param  string  $scope
     * @param  string|null  $alias
     * @return void
     */
    #[DataProvider('invalidNameProvider')]
    public function testANameAClientCannotInvokeIsRefused(string $scope, ?string $alias): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException(sprintf('Scope name "%s" must be a letter followed by letters, digits or underscores', $alias ?? $scope)));

        Scope::of($scope, $alias);
    }

    /**
     * Test that the parameters, description and caller guards carry through to
     * the array.
     *
     * @return void
     */
    public function testModifiersCarryThroughToTheArray(): void
    {
        $guard = static fn (): bool => true;
        $rules = ['lat' => ['required', 'numeric'], 'ids.*' => 'integer'];

        $array = Scope::of('near')->parameters($rules)->description('Near the point.')->visibleTo($guard)->visibleTo($guard)->toArray();

        self::assertSame(
            ['__scope__:near' => ['scope' => 'near', 'key' => 'near', 'parameters' => $rules, 'description' => 'Near the point.', 'caller_guards' => [$guard, $guard]]],
            $array,
        );
    }

    /**
     * Test that the arguments rules declare are the distinct first segments of
     * their keys, in order.
     *
     * @return void
     */
    public function testArgumentsAreTheDistinctFirstSegmentsOfTheRuleKeys(): void
    {
        self::assertSame(['ids', 'near', '0'], Scope::argumentsOf(['ids' => 'array', 'ids.*' => 'integer', 'near.lat' => 'numeric', '0' => 'string']));
        self::assertSame([], Scope::argumentsOf([]));
    }

    /**
     * Test that a scope declared twice under one name is refused by the set.
     *
     * @return void
     */
    public function testAScopeDeclaredTwiceUnderOneNameIsRefusedByTheSet(): void
    {
        $this->expectExceptionObject(new DuplicateSchemaKeyException('Duplicate schema key "__scope__:mine" detected in Field::set()'));

        Field::set(Scope::of('mine'), Scope::of('ownedByCaller', 'mine'));
    }

    /**
     * Test that a scope sharing a field's name stays out of the field map.
     *
     * @return void
     */
    public function testAScopeSharingAFieldsNameStaysOutOfTheFieldMap(): void
    {
        self::assertSame(['upcoming', '__scope__:upcoming'], array_keys(Field::set(Field::scalar('upcoming'), Scope::of('upcoming'))));
    }
}
