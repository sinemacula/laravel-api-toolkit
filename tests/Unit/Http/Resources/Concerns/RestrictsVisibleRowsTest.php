<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RestrictsVisibleRows;
use Tests\Fixtures\Models\Post;
use Tests\TestCase;

/**
 * Tests for the RestrictsVisibleRows trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(RestrictsVisibleRows::class)]
final class RestrictsVisibleRowsTest extends TestCase
{
    /**
     * Test that a resource using the trait exposes every row by default.
     *
     * @return void
     */
    public function testExposesEveryRowByDefault(): void
    {
        $resource = new class {
            use RestrictsVisibleRows;
        };

        $query = Post::query();

        $resource::restrictRows($query, Request::create('/'));

        self::assertSame('select * from "posts"', $query->toSql());
    }
}
