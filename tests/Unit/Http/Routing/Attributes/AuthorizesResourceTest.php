<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Routing\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use Tests\Fixtures\Models\User;

/**
 * Tests for the AuthorizesResource attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AuthorizesResource::class)]
final class AuthorizesResourceTest extends TestCase
{
    /**
     * Test that the attribute defaults the parameter and except values.
     *
     * @return void
     */
    public function testDefaultsParameterAndExcept(): void
    {
        $attribute = new AuthorizesResource(User::class);

        self::assertSame(User::class, $attribute->model);
        self::assertNull($attribute->parameter);
        self::assertSame([], $attribute->except);
    }

    /**
     * Test that the attribute retains every explicitly provided value.
     *
     * @return void
     */
    public function testRetainsProvidedValues(): void
    {
        $attribute = new AuthorizesResource(User::class, parameter: 'User', except: ['index', 'show']);

        self::assertSame(User::class, $attribute->model);
        self::assertSame('User', $attribute->parameter);
        self::assertSame(['index', 'show'], $attribute->except);
    }
}
