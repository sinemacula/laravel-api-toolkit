<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Support\EnumCast;
use Tests\Fixtures\Enums\CastablePlatform;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;

/**
 * Tests for recognising a model cast that stores an enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnumCast::class)]
final class EnumCastTest extends TestCase
{
    /**
     * Test that a cast naming a backed or a pure enum returns that enum.
     *
     * @return void
     */
    public function testEnumOfReturnsTheEnumACastNames(): void
    {
        self::assertSame(UserStatus::class, EnumCast::enumOf(UserStatus::class));
        self::assertSame(Platform::class, EnumCast::enumOf(Platform::class));
    }

    /**
     * Provide casts that store no enum Eloquent casts itself.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function otherCastProvider(): iterable
    {
        yield 'no cast' => [null];
        yield 'primitive cast' => ['string'];
        yield 'castable enum' => [CastablePlatform::class];
        yield 'model class' => [User::class];
        yield 'non-string cast' => [['array']];
    }

    /**
     * Test that a cast storing anything but an enum Eloquent casts itself
     * returns null.
     *
     * @param  mixed  $cast
     * @return void
     */
    #[DataProvider('otherCastProvider')]
    public function testEnumOfReturnsNullForAnyOtherCast(mixed $cast): void
    {
        self::assertNull(EnumCast::enumOf($cast));
    }
}
