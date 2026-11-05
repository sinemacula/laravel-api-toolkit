<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Support\PureEnumCast;
use Tests\Fixtures\Enums\CastablePlatform;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;

/**
 * Tests for recognising a model cast that stores a pure enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PureEnumCast::class)]
final class PureEnumCastTest extends TestCase
{
    /**
     * Test that a cast naming a pure enum returns that enum.
     *
     * @return void
     */
    public function testEnumOfReturnsThePureEnumACastNames(): void
    {
        self::assertSame(Platform::class, PureEnumCast::enumOf(Platform::class));
    }

    /**
     * Provide casts that store no pure enum.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function otherCastProvider(): iterable
    {
        yield 'no cast' => [null];
        yield 'primitive cast' => ['string'];
        yield 'backed enum' => [UserStatus::class];
        yield 'castable enum' => [CastablePlatform::class];
        yield 'model class' => [User::class];
        yield 'non-string cast' => [['array']];
    }

    /**
     * Test that a cast storing anything but a pure enum returns null.
     *
     * @param  mixed  $cast
     * @return void
     */
    #[DataProvider('otherCastProvider')]
    public function testEnumOfReturnsNullForAnyOtherCast(mixed $cast): void
    {
        self::assertNull(PureEnumCast::enumOf($cast));
    }
}
