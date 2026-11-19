<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\DuplicateSchemaKeyException;
use SineMacula\ApiToolkit\Schema\AggregateDefinition;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Tests for the AggregateDefinition abstract class, through its subclasses.
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
        $key   = '__aggregate__:posts_sum_id';

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

        self::assertArrayHasKey('__aggregate__:my_alias', $array);
        self::assertSame('my_alias', $array['__aggregate__:my_alias']['key']);
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

        self::assertArrayHasKey('__aggregate__:total_sum', $array);
        self::assertSame('total_sum', $array['__aggregate__:total_sum']['key']);
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

        self::assertSame($constraint, $array['__aggregate__:posts_sum_id']['constraint']);
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

        self::assertTrue($array['__aggregate__:posts_sum_id']['default']);
    }

    /**
     * Test that toArray uses Laravel's default aggregate key as the present key
     * when no alias is set.
     *
     * @return void
     */
    public function testToArrayUsesTheDefaultKeyAsPresentKeyWhenNoAlias(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();
        $key   = '__aggregate__:posts_sum_id';

        self::assertSame('posts_sum_id', $array[$key]['key']);
    }

    /**
     * Test that a count and an existence check sharing one key collide in one
     * schema, whatever their functions.
     *
     * @return void
     */
    public function testAggregatesSharingAKeyCollideAcrossFunctions(): void
    {
        $this->expectException(DuplicateSchemaKeyException::class);
        $this->expectExceptionMessage('Duplicate schema key "__aggregate__:x" detected in Field::set()');

        Field::set(Count::of('posts', 'x'), Exists::of('posts', 'x'));
    }

    /**
     * Test that a count emits no column key.
     *
     * @return void
     */
    public function testCountEmitsNoColumn(): void
    {
        $array = Count::of('posts')->toArray();

        self::assertSame(['key', 'metric', 'relation'], array_keys($array['__aggregate__:posts_count']));
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

        self::assertSame('sum', $array['__aggregate__:posts_sum_id']['metric']);
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
        $data  = $array['__aggregate__:posts_sum_id'];

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
        $data  = $array['__aggregate__:alias_sum'];

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

        self::assertArrayHasKey('openapi', $array['__aggregate__:posts_sum_id']);
    }

    /**
     * Test that a sortable aggregate emits the sortable marker and its reason.
     *
     * @return void
     */
    public function testSortableAggregateEmitsTheMarkerAndItsReason(): void
    {
        $data = Count::of('posts')->sortable('Leaderboard')->toArray()['__aggregate__:posts_count'];

        self::assertSame(['key', 'metric', 'relation', 'sortable', 'unindexed'], array_keys($data));
        self::assertTrue($data['sortable']);
        self::assertSame('Leaderboard', $data['unindexed']);
    }
}
