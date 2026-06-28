<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\AggregateDefinition;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Tests for the AggregateDefinition abstract class.
 *
 * Uses Sum as the concrete implementation since AggregateDefinition is
 * abstract.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AggregateDefinition::class)]
final class AggregateDefinitionTest extends TestCase
{
    /**
     * Test that of creates an instance with the given relation and column.
     *
     * @return void
     */
    public function testOfCreatesInstanceWithRelationAndColumn(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();
        $key   = '__sum__:posts_id';

        self::assertArrayHasKey($key, $array);
        self::assertSame('posts', $array[$key]['relation']);
        self::assertSame('id', $array[$key]['column']);
    }

    /**
     * Test that of with an explicit alias uses the alias as the present key.
     *
     * @return void
     */
    public function testOfWithExplicitAliasUsesThatAliasAsPresentKey(): void
    {
        $sum   = Sum::of('posts', 'id', 'my_alias');
        $array = $sum->toArray();

        self::assertArrayHasKey('__sum__:my_alias', $array);
        self::assertSame('my_alias', $array['__sum__:my_alias']['key']);
    }

    /**
     * Test that as() updates the alias and re-keys the output.
     *
     * @return void
     */
    public function testAsUpdatesAliasAndRekeys(): void
    {
        $sum = Sum::of('posts', 'id');

        $result = $sum->as('total_sum');

        self::assertSame($sum, $result);

        $array = $sum->toArray();

        self::assertArrayHasKey('__sum__:total_sum', $array);
        self::assertSame('total_sum', $array['__sum__:total_sum']['key']);
    }

    /**
     * Test that constrain stores the closure and emits it in toArray.
     *
     * @return void
     */
    public function testConstrainStoresClosureInArray(): void
    {
        $constraint = fn ($query) => $query->where('active', true);
        $sum        = Sum::of('posts', 'id');

        $result = $sum->constrain($constraint);

        self::assertSame($sum, $result);

        $array = $sum->toArray();

        self::assertSame($constraint, $array['__sum__:posts_id']['constraint']);
    }

    /**
     * Test that default marks the aggregate as a default metric.
     *
     * @return void
     */
    public function testDefaultMarksAggregateAsDefault(): void
    {
        $sum = Sum::of('posts', 'id');

        $result = $sum->default();

        self::assertSame($sum, $result);

        $array = $sum->toArray();

        self::assertTrue($array['__sum__:posts_id']['default']);
    }

    /**
     * Test that toArray uses relation_column as the present key when no alias
     * is set.
     *
     * @return void
     */
    public function testToArrayUsesRelationColumnAsPresentKeyWhenNoAlias(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();
        $key   = '__sum__:posts_id';

        self::assertSame('posts_id', $array[$key]['key']);
    }

    /**
     * Test that toArray emits the metric key matching the concrete subclass.
     *
     * @return void
     */
    public function testToArrayEmitsMetricFromConcreteSubclass(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();

        self::assertSame('sum', $array['__sum__:posts_id']['metric']);
    }

    /**
     * Test that toArray omits optional keys when they are not set.
     *
     * @return void
     */
    public function testToArrayOmitsOptionalKeysWhenNotSet(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();
        $data  = $array['__sum__:posts_id'];

        self::assertArrayNotHasKey('constraint', $data);
        self::assertArrayNotHasKey('default', $data);
        self::assertArrayNotHasKey('extras', $data);
        self::assertArrayNotHasKey('guards', $data);
        self::assertArrayNotHasKey('transformers', $data);
    }

    /**
     * Test that toArray includes all properties when fully configured.
     *
     * @return void
     */
    public function testToArrayIncludesAllPropertiesWhenFullyConfigured(): void
    {
        $constraint  = fn ($query) => $query->where('active', true);
        $guard       = fn () => true;
        $transformer = fn ($resource, $value) => $value;

        $sum = Sum::of('posts', 'id', 'alias_sum')
            ->constrain($constraint)
            ->default()
            ->guard($guard)
            ->transform($transformer)
            ->extras('posts.comments');

        $array = $sum->toArray();
        $data  = $array['__sum__:alias_sum'];

        self::assertSame('alias_sum', $data['key']);
        self::assertSame('sum', $data['metric']);
        self::assertSame('posts', $data['relation']);
        self::assertSame('id', $data['column']);
        self::assertSame($constraint, $data['constraint']);
        self::assertTrue($data['default']);
        self::assertSame([$guard], $data['guards']);
        self::assertSame([$transformer], $data['transformers']);
        self::assertSame(['posts.comments'], $data['extras']);
    }

    /**
     * Test that toArray returns exactly one top-level entry.
     *
     * @return void
     */
    public function testToArrayReturnsExactlyOneEntry(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();

        self::assertCount(1, $array);
    }

    /**
     * Test that toArray includes the openapi key when an OpenAPI declaration
     * has been attached to the aggregate definition.
     *
     * @return void
     */
    public function testToArrayIncludesOpenApiDeclarationWhenSet(): void
    {
        $sum = Sum::of('posts', 'id');
        $sum->openapi()->type('integer');

        $array = $sum->toArray();

        self::assertArrayHasKey('openapi', $array['__sum__:posts_id']);
    }
}
