<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\CallableList;

/**
 * Tests for the CallableList enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CallableList::class)]
final class CallableListTest extends TestCase
{
    /**
     * Test that the enum names exactly the guard and transformer lists.
     *
     * @return void
     */
    public function testDeclaresGuardAndTransformerLists(): void
    {
        self::assertSame([CallableList::GUARDS, CallableList::TRANSFORMERS], CallableList::cases());
    }

    /**
     * Test that each list reports its entries under its own label.
     *
     * @return void
     */
    public function testLabelNamesASingleEntry(): void
    {
        self::assertSame('Guard', CallableList::GUARDS->label());
        self::assertSame('Transformer', CallableList::TRANSFORMERS->label());
    }

    /**
     * Test that the guard list selects the guards and ignores the transformers.
     *
     * @return void
     */
    public function testGuardsSelectsTheGuardList(): void
    {
        $guards       = [fn ($value, $model) => true];
        $transformers = [fn ($value, $model) => $value];

        self::assertSame($guards, CallableList::GUARDS->select($guards, $transformers));
    }

    /**
     * Test that the transformer list selects the transformers and ignores the
     * guards.
     *
     * @return void
     */
    public function testTransformersSelectsTheTransformerList(): void
    {
        $guards       = [fn ($value, $model) => true];
        $transformers = [fn ($value, $model) => $value];

        self::assertSame($transformers, CallableList::TRANSFORMERS->select($guards, $transformers));
    }
}
