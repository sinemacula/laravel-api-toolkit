<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use Tests\Fixtures\Enums\Alternate\Tier as AlternateTier;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\CustomNamedResource;
use Tests\Fixtures\Resources\EmptyNamedResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Resources\V1\UserResource as V1UserResource;
use Tests\Fixtures\Resources\V2\UserResource as V2UserResource;

/**
 * Tests for the SchemaComponentName helper.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaComponentName::class)]
final class SchemaComponentNameTest extends TestCase
{
    /**
     * Test that the trailing Resource suffix is stripped.
     *
     * @return void
     */
    public function testStripsTheTrailingResourceSuffixFromTheBasename(): void
    {
        self::assertSame('User', SchemaComponentName::fromResource(UserResource::class));
        self::assertSame('Organization', SchemaComponentName::fromResource(OrganizationResource::class));
    }

    /**
     * Test that a basename without the Resource suffix is unchanged.
     *
     * @return void
     */
    public function testReturnsTheBasenameWhenThereIsNoResourceSuffix(): void
    {
        self::assertSame('User', SchemaComponentName::fromResource(User::class));
    }

    /**
     * Test that the derivation ignores the namespace.
     *
     * @return void
     */
    public function testTwoResourcesSharingABasenameAcrossNamespacesCollide(): void
    {
        $v1 = SchemaComponentName::fromResource(V1UserResource::class);
        $v2 = SchemaComponentName::fromResource(V2UserResource::class);

        self::assertSame('User', $v1);
        self::assertSame('User', $v2);
        self::assertSame($v1, $v2);
    }

    /**
     * Test that a #[SchemaName] override beats the basename derivation.
     *
     * @return void
     */
    public function testSchemaNameOverrideWinsOverTheBasename(): void
    {
        self::assertSame('CustomName', SchemaComponentName::fromResource(CustomNamedResource::class));
    }

    /**
     * Test that an empty #[SchemaName] override is ignored.
     *
     * @return void
     */
    public function testEmptySchemaNameOverrideFallsBackToTheBasename(): void
    {
        self::assertSame('EmptyNamed', SchemaComponentName::fromResource(EmptyNamedResource::class));
    }

    /**
     * Test that an enum keeps its basename verbatim.
     *
     * @return void
     */
    public function testEnumKeepsItsBasenameVerbatim(): void
    {
        self::assertSame('UserStatus', SchemaComponentName::fromEnum(UserStatus::class));
    }

    /**
     * Test that a #[SchemaName] override on an enum beats its basename.
     *
     * @return void
     */
    public function testSchemaNameOverrideWinsOverTheEnumBasename(): void
    {
        self::assertSame('AlternateTier', SchemaComponentName::fromEnum(AlternateTier::class));
    }

    /**
     * Test that a class the autoloader cannot produce names nothing.
     *
     * @return void
     */
    public function testAClassThatCannotBeLoadedCarriesNoOverride(): void
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([User::class => 'Tests\Fixtures\Resources\MissingResource']);

        self::assertSame([User::class => 'Missing'], array_map(SchemaComponentName::fromResource(...), $catalogue->getResourceMap()));
    }
}
