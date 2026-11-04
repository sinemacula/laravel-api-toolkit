<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Support\PureEnumName;
use Tests\Fixtures\Enums\CollidingPlatform;
use Tests\Fixtures\Enums\Platform;

/**
 * Tests for the PureEnumName wire-form convention.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PureEnumName::class)]
final class PureEnumNameTest extends TestCase
{
    /**
     * Test that a case's wire name is its case name lowercased, keeping any
     * underscore.
     *
     * @return void
     */
    public function testOfReturnsTheLowercaseCaseName(): void
    {
        self::assertSame('web', PureEnumName::of(Platform::WEB));
        self::assertSame('mobile_app', PureEnumName::of(Platform::MOBILE_APP));
    }

    /**
     * Test that each wire name resolves back to its own case.
     *
     * @return void
     */
    public function testTryFromResolvesEachWireName(): void
    {
        foreach (Platform::cases() as $case) {
            self::assertSame($case, PureEnumName::tryFrom(Platform::class, PureEnumName::of($case)));
        }
    }

    /**
     * Provide values that name no case in wire form.
     *
     * @return iterable<string, array{string}>
     */
    public static function unmatchedValueProvider(): iterable
    {
        yield 'upper case' => ['WEB'];
        yield 'mixed case' => ['Web'];
        yield 'unknown' => ['console'];
        yield 'hyphenated' => ['mobile-app'];
        yield 'empty' => [''];
    }

    /**
     * Test that a value which is not exactly a lowercase case name resolves to
     * no case.
     *
     * @param  string  $value
     * @return void
     */
    #[DataProvider('unmatchedValueProvider')]
    public function testTryFromRefusesAValueThatIsNotExactlyAWireName(string $value): void
    {
        self::assertNull(PureEnumName::tryFrom(Platform::class, $value));
    }

    /**
     * Test that reading the wire name of a case fails loud when two cases of
     * its enum share a lowercase name.
     *
     * @return void
     */
    public function testOfFailsWhenCaseNamesCollideOnceLowercased(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The cases Web and WEB of ' . CollidingPlatform::class . ' share the wire name "web"');

        PureEnumName::of(CollidingPlatform::WEB);
    }

    /**
     * Test that resolving a value fails loud when two cases of the enum share a
     * lowercase name, rather than picking one.
     *
     * @return void
     */
    public function testTryFromFailsWhenCaseNamesCollideOnceLowercased(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The cases Web and WEB of ' . CollidingPlatform::class . ' share the wire name "web"');

        PureEnumName::tryFrom(CollidingPlatform::class, 'web');
    }
}
