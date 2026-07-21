<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\BaseDefinition;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\OpenApiFieldDeclaration;

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
final class BaseDefinitionTest extends TestCase
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

        self::assertSame($field, $result);
        self::assertCount(1, $field->getGuards());
        self::assertSame($guard, $field->getGuards()[0]);
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

        self::assertCount(2, $guards);
        self::assertSame($guard1, $guards[0]);
        self::assertSame($guard2, $guards[1]);
    }

    /**
     * Test that getGuards returns an empty array when no guards are set.
     *
     * @return void
     */
    public function testGetGuardsReturnsEmptyArrayByDefault(): void
    {
        $field = Field::scalar('name');

        self::assertSame([], $field->getGuards());
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

        self::assertSame($field, $result);
        self::assertCount(1, $field->getTransformers());
        self::assertSame($transformer, $field->getTransformers()[0]);
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

        self::assertCount(2, $transformers);
        self::assertSame($transformer1, $transformers[0]);
        self::assertSame($transformer2, $transformers[1]);
    }

    /**
     * Test that getTransformers returns an empty array when none are set.
     *
     * @return void
     */
    public function testGetTransformersReturnsEmptyArrayByDefault(): void
    {
        $field = Field::scalar('name');

        self::assertSame([], $field->getTransformers());
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

        self::assertSame(['relation.a', 'relation.b'], $array['name']['extras']);
    }

    /**
     * Test that extras reindexes sequentially when deduplication removes an
     * interior duplicate.
     *
     * @return void
     */
    public function testExtrasReindexesAfterInteriorDeduplication(): void
    {
        $field = Field::scalar('name');

        $field->extras('relation.a', 'relation.b');
        $field->extras('relation.a', 'relation.c');

        $array = $field->toArray();

        self::assertSame(['relation.a', 'relation.b', 'relation.c'], $array['name']['extras']);
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

        self::assertSame($field, $result);
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

        self::assertSame(['relation.a', 'relation.b'], $array['name']['extras']);
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

        self::assertCount(3, $field->getGuards());
    }

    /**
     * Test that needs accumulates columns across calls and deduplicates them.
     *
     * @return void
     */
    public function testNeedsAccumulatesColumnsAndDeduplicates(): void
    {
        $field = Field::scalar('name');

        $field->needs('first_name')->needs('last_name', 'first_name');

        $array = $field->toArray();

        self::assertSame(['first_name', 'last_name'], $array['name']['needs']);
    }

    /**
     * Test that needs returns self for fluent chaining.
     *
     * @return void
     */
    public function testNeedsReturnsSelfForChaining(): void
    {
        $field = Field::scalar('name');

        self::assertSame($field, $field->needs('first_name'));
    }

    /**
     * Test that openapi returns a declaration carrier.
     *
     * @return void
     */
    public function testOpenapiReturnsDeclarationCarrier(): void
    {
        $field = Field::scalar('name');

        $declaration = $field->openapi();

        self::assertInstanceOf(OpenApiFieldDeclaration::class, $declaration);
    }

    /**
     * Test that openapi returns the same carrier on repeated calls.
     *
     * @return void
     */
    public function testOpenapiReturnsSameCarrierOnRepeatedCalls(): void
    {
        $field = Field::scalar('name');

        self::assertSame($field->openapi(), $field->openapi());
    }

    /**
     * Test that the carrier end() chains back to the owning definition.
     *
     * @return void
     */
    public function testOpenapiCarrierEndReturnsOwningDefinition(): void
    {
        $field = Field::scalar('name');

        self::assertSame($field, $field->openapi()->end());
    }

    /**
     * Test that getOpenApiDeclaration returns null until openapi is called.
     *
     * @return void
     */
    public function testGetOpenApiDeclarationReturnsNullByDefault(): void
    {
        $field = Field::scalar('name');

        self::assertNull($field->getOpenApiDeclaration());
    }

    /**
     * Test that getOpenApiDeclaration returns the carrier after openapi is
     * called.
     *
     * @return void
     */
    public function testGetOpenApiDeclarationReturnsCarrierAfterDeclaration(): void
    {
        $field = Field::scalar('name');

        $declaration = $field->openapi();

        self::assertSame($declaration, $field->getOpenApiDeclaration());
    }

    /**
     * Test that a definition with no openapi declaration serializes identically
     * to the pre-feature output.
     *
     * This is the byte-for-byte backward-compatibility oracle (AC-11): the
     * openapi key must never appear unless openapi() was explicitly called.
     *
     * @return void
     */
    public function testToArrayIsUnchangedWhenOpenapiNotDeclared(): void
    {
        $guard       = fn () => true;
        $transformer = fn ($resource, $value) => $value;

        $field = Field::accessor('display_name', 'name')
            ->extras('profile')
            ->guard($guard)
            ->transform($transformer);

        self::assertSame([
            'display_name' => [
                'accessor'     => 'name',
                'extras'       => ['profile'],
                'guards'       => [$guard],
                'transformers' => [$transformer],
            ],
        ], $field->toArray());
    }
}
