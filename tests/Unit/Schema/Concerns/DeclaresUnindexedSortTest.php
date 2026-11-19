<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Concerns;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Concerns\DeclaresUnindexedSort;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Resources\OrganizationResource;

/**
 * Tests for the DeclaresUnindexedSort trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(DeclaresUnindexedSort::class)]
final class DeclaresUnindexedSortTest extends TestCase
{
    /**
     * Test that a definition not declared sortable emits no sort attributes.
     *
     * @return void
     */
    public function testUndeclaredDefinitionEmitsNoSortAttributes(): void
    {
        $data = Count::of('posts')->toArray()['__aggregate__:posts_count'];

        self::assertArrayNotHasKey('sortable', $data);
        self::assertArrayNotHasKey('unindexed', $data);
    }

    /**
     * Test that sortable() trims the reason and returns the same instance.
     *
     * @return void
     */
    public function testSortableTrimsTheReasonAndReturnsSelf(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        self::assertSame($relation, $relation->sortable('  Back-office list  '));
        self::assertSame('Back-office list', $relation->toArray()['organization']['unindexed']);
    }

    /**
     * Provide the reasons that carry no text.
     *
     * @return iterable<string, array{string}>
     */
    public static function blankReasonProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
    }

    /**
     * Test that a blank reason is refused.
     *
     * @param  string  $reason
     * @return void
     */
    #[DataProvider('blankReasonProvider')]
    public function testBlankReasonIsRefused(string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A sortable relation or aggregate must carry the reason its order is unindexed');

        Count::of('posts')->sortable($reason);
    }
}
