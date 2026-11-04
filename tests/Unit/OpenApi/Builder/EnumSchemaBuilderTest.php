<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use Tests\Fixtures\Enums\Alternate\Tier as AlternateTier;
use Tests\Fixtures\Enums\CollidingPlatform;
use Tests\Fixtures\Enums\DocumentFormat;
use Tests\Fixtures\Enums\Naming\Tier;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\Ranking\Tier as RankingTier;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Tests for the EnumSchemaBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnumSchemaBuilder::class)]
final class EnumSchemaBuilderTest extends TestCase
{
    /**
     * Test that a string-backed enum emits a string-typed component keyed by
     * its basename, listing each case's backing value.
     *
     * @return void
     */
    public function testStringBackedEnumEmitsStringComponent(): void
    {
        $schemas = (new EnumSchemaBuilder)->build([UserStatus::class]);

        self::assertSame(['UserStatus' => ['type' => 'string', 'enum' => ['active', 'inactive', 'banned']]], $schemas);
    }

    /**
     * Test that an int-backed enum emits an integer-typed component listing
     * each case's backing value.
     *
     * @return void
     */
    public function testIntBackedEnumEmitsIntegerComponent(): void
    {
        $schemas = (new EnumSchemaBuilder)->build([UserLevel::class]);

        self::assertSame(['UserLevel' => ['type' => 'integer', 'enum' => [1, 2, 3]]], $schemas);
    }

    /**
     * Test that a non-backed enum emits a string-typed component listing each
     * case's lowercase name.
     *
     * @return void
     */
    public function testNonBackedEnumEmitsLowercaseNameComponent(): void
    {
        $schemas = (new EnumSchemaBuilder)->build([DocumentFormat::class]);

        self::assertSame(['DocumentFormat' => ['type' => 'string', 'enum' => ['pdf', 'html']]], $schemas);
    }

    /**
     * Test that a pure enum's component lists each multi-word case name
     * lowercased with its underscore kept, in declaration order.
     *
     * @return void
     */
    public function testPureEnumComponentListsLowercaseNamesInDeclarationOrder(): void
    {
        $schemas = (new EnumSchemaBuilder)->build([Platform::class]);

        self::assertSame(['Platform' => ['type' => 'string', 'enum' => ['web', 'mobile_app', 'desktop']]], $schemas);
    }

    /**
     * Test that a pure enum whose case names collide once lowercased fails loud
     * rather than documenting an ambiguous value.
     *
     * @return void
     */
    public function testPureEnumWithCollidingCaseNamesThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(CollidingPlatform::class);

        (new EnumSchemaBuilder)->build([CollidingPlatform::class]);
    }

    /**
     * Test that a #[SchemaName] override keys the component under the override
     * name rather than the enum basename.
     *
     * @return void
     */
    public function testSchemaNameOverrideKeysTheComponent(): void
    {
        $schemas = (new EnumSchemaBuilder)->build([AlternateTier::class]);

        self::assertArrayHasKey('AlternateTier', $schemas);
        self::assertArrayNotHasKey('Tier', $schemas);
    }

    /**
     * Test that two enums sharing a basename fail loud, naming both enum
     * classes.
     *
     * @return void
     */
    public function testTwoEnumsSharingABasenameThrow(): void
    {
        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(Tier::class);
        $this->expectExceptionMessage(RankingTier::class);

        (new EnumSchemaBuilder)->build([Tier::class, RankingTier::class]);
    }

    /**
     * Test that a #[SchemaName] override on one of two basename-colliding enums
     * disambiguates them, so both components survive under distinct keys.
     *
     * @return void
     */
    public function testSchemaNameOverrideResolvesTheEnumCollision(): void
    {
        $schemas = (new EnumSchemaBuilder)->build([Tier::class, AlternateTier::class]);

        self::assertArrayHasKey('Tier', $schemas);
        self::assertArrayHasKey('AlternateTier', $schemas);
    }

    /**
     * Test that an enum whose name collides with a name already reserved by a
     * resource fails loud, naming both the resource and the enum.
     *
     * @return void
     */
    public function testEnumCollidingWithAReservedResourceNameThrows(): void
    {
        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage('App\UserStatusResource');
        $this->expectExceptionMessage(UserStatus::class);

        (new EnumSchemaBuilder)->build([UserStatus::class], ['UserStatus' => 'App\UserStatusResource']);
    }
}
