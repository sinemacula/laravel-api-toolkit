<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;

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
}
