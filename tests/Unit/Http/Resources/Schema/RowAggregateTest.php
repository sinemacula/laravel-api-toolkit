<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\RowAggregate;

/**
 * Tests for the RowAggregate abstract class, through its subclasses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RowAggregate::class)]
final class RowAggregateTest extends TestCase
{
    /**
     * Test that of builds the concrete subclass it is called on.
     *
     * @return void
     */
    public function testOfBuildsTheCalledSubclass(): void
    {
        self::assertInstanceOf(Count::class, Count::of('posts'));
        self::assertInstanceOf(Exists::class, Exists::of('posts'));
    }

    /**
     * Test that of carries the relation and the alias, and no column.
     *
     * @return void
     */
    public function testOfCarriesTheRelationAndAliasWithoutAColumn(): void
    {
        $array = Exists::of('messages', 'has_messages')->toArray();

        self::assertSame(['key' => 'has_messages', 'metric' => 'exists', 'relation' => 'messages'], $array['__aggregate__:has_messages']);
    }
}
