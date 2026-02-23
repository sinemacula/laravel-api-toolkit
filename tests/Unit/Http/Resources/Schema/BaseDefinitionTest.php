<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\BaseDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

/**
 * Tests for the BaseDefinition abstract class.
 *
 * Uses Field as the concrete implementation since BaseDefinition is abstract.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BaseDefinition::class)]
class BaseDefinitionTest extends TestCase
{
    /**
     * Test that guard adds a callable and returns self for fluent chaining.
     *
     * @return void
     */
    public function testGuardAddsCallableAndReturnsSelf(): void
    {
        $field = Field::scalar('name');
        $guard = fn () => true;

        $result = $field->guard($guard);

        static::assertSame($field, $result);
        static::assertCount(1, $field->getGuards());
        static::assertSame($guard, $field->getGuards()[0]);
    }

    /**
     * Test that getGuards returns all registered guards.
     *
     * @return void
     */
    public function testGetGuardsReturnsAllRegisteredGuards(): void
    {
        $field  = Field::scalar('name');
        $guard1 = fn () => true;
        $guard2 = fn () => false;

        $field->guard($guard1)->guard($guard2);

        $guards = $field->getGuards();

        static::assertCount(2, $guards);
        static::assertSame($guard1, $guards[0]);
        static::assertSame($guard2, $guards[1]);
    }

    /**
     * Test that getGuards returns an empty array when no guards are set.
     *
     * @return void
     */
    public function testGetGuardsReturnsEmptyArrayByDefault(): void
    {
        $field = Field::scalar('name');

        static::assertSame([], $field->getGuards());
    }

    /**
     * Test that transform adds a callable and returns self for fluent chaining.
     *
     * @return void
     */
    public function testTransformAddsCallableAndReturnsSelf(): void
    {
        $field       = Field::scalar('name');
        $transformer = fn ($resource, $value) => strtoupper($value);

        $result = $field->transform($transformer);

        static::assertSame($field, $result);
        static::assertCount(1, $field->getTransformers());
        static::assertSame($transformer, $field->getTransformers()[0]);
    }

    /**
     * Test that getTransformers returns all registered transformers.
     *
     * @return void
     */
    public function testGetTransformersReturnsAllRegisteredTransformers(): void
    {
        $field        = Field::scalar('name');
        $transformer1 = fn ($resource, $value) => strtoupper($value);
        $transformer2 = fn ($resource, $value) => trim($value);

        $field->transform($transformer1)->transform($transformer2);

        $transformers = $field->getTransformers();

        static::assertCount(2, $transformers);
        static::assertSame($transformer1, $transformers[0]);
        static::assertSame($transformer2, $transformers[1]);
    }

    /**
     * Test that getTransformers returns an empty array when none are set.
     *
     * @return void
     */
    public function testGetTransformersReturnsEmptyArrayByDefault(): void
    {
        $field = Field::scalar('name');

        static::assertSame([], $field->getTransformers());
    }

    /**
     * Test that extras adds paths and deduplicates them.
     *
     * @return void
     */
    public function testExtrasAddsPathsAndDeduplicates(): void
    {
        $field = Field::scalar('name');

        $field->extras('relation.a', 'relation.b', 'relation.a');

        $array = $field->toArray();

        static::assertSame(['relation.a', 'relation.b'], $array['name']['extras']);
    }

    /**
     * Test that extras returns self for fluent chaining.
     *
     * @return void
     */
    public function testExtrasReturnsSelfForChaining(): void
    {
        $field = Field::scalar('name');

        $result = $field->extras('relation.a');

        static::assertSame($field, $result);
    }

    /**
     * Test that extras can be called multiple times and merges paths.
     *
     * @return void
     */
    public function testExtrasCanBeCalledMultipleTimesAndMerges(): void
    {
        $field = Field::scalar('name');

        $field->extras('relation.a')->extras('relation.b', 'relation.a');

        $array = $field->toArray();

        static::assertSame(['relation.a', 'relation.b'], $array['name']['extras']);
    }

    /**
     * Test that multiple guards can be stacked via fluent chaining.
     *
     * @return void
     */
    public function testMultipleGuardsCanBeStacked(): void
    {
        $field  = Field::scalar('name');
        $guard1 = fn () => true;
        $guard2 = fn () => true;
        $guard3 = fn () => false;

        $field->guard($guard1)->guard($guard2)->guard($guard3);

        static::assertCount(3, $field->getGuards());
    }
}
