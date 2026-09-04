<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
     * Test that the trailing Resource suffix is stripped from the class
     * basename.
     *
     * @return void
     */
    public function testStripsTheTrailingResourceSuffixFromTheBasename(): void
    {
        self::assertSame('User', SchemaComponentName::fromResource(UserResource::class));
        self::assertSame('Organization', SchemaComponentName::fromResource(OrganizationResource::class));
    }

    /**
     * Test that a class basename with no Resource suffix is returned unchanged,
     * so only a trailing suffix is stripped.
     *
     * @return void
     */
    public function testReturnsTheBasenameWhenThereIsNoResourceSuffix(): void
    {
        self::assertSame('User', SchemaComponentName::fromResource(User::class));
    }

    /**
     * Test that two distinct resource classes sharing a basename across version
     * namespaces both derive the same component name, so the derivation is
     * driven purely by the basename and ignores the namespace.
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
     * Test that a #[SchemaName] override on the class wins over the basename
     * derivation, so a resource can declare a distinct component name.
     *
     * @return void
     */
    public function testSchemaNameOverrideWinsOverTheBasename(): void
    {
        self::assertSame('CustomName', SchemaComponentName::fromResource(CustomNamedResource::class));
    }

    /**
     * Test that an empty #[SchemaName] override is ignored and the derivation
     * falls back to the class basename.
     *
     * @return void
     */
    public function testEmptySchemaNameOverrideFallsBackToTheBasename(): void
    {
        self::assertSame('EmptyNamed', SchemaComponentName::fromResource(EmptyNamedResource::class));
    }

    /**
     * Test that an enum keeps its class basename verbatim, with no Resource
     * suffix stripping applied.
     *
     * @return void
     */
    public function testEnumKeepsItsBasenameVerbatim(): void
    {
        self::assertSame('UserStatus', SchemaComponentName::fromEnum(UserStatus::class));
    }

    /**
     * Test that a #[SchemaName] override on an enum wins over its basename, so
     * two basename-colliding enums can declare distinct component names.
     *
     * @return void
     */
    public function testSchemaNameOverrideWinsOverTheEnumBasename(): void
    {
        self::assertSame('AlternateTier', SchemaComponentName::fromEnum(AlternateTier::class));
    }

    /**
     * Test that a name is not read off a class the autoloader cannot produce,
     * so a stale mapping entry names nothing rather than raising.
     *
     * @return void
     */
    public function testAClassThatCannotBeLoadedCarriesNoOverride(): void
    {
        self::assertSame('Missing', SchemaComponentName::fromResource('Tests\Fixtures\Resources\MissingResource'));
    }
}
