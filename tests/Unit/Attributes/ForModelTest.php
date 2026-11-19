<?php

declare(strict_types = 1);

namespace Tests\Unit\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Attributes\ForModel;
use Tests\Fixtures\Discovery\Multi\MultiModelResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;

/**
 * Tests for the ForModel attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ForModel::class)]
final class ForModelTest extends TestCase
{
    /**
     * Test that a resource declares the attribute once per model it presents.
     *
     * @return void
     */
    public function testAResourceDeclaresOneBindingPerModel(): void
    {
        $models = array_map(
            static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->model,
            (new \ReflectionClass(MultiModelResource::class))->getAttributes(ForModel::class),
        );

        self::assertSame([User::class, Post::class], $models);
    }
}
