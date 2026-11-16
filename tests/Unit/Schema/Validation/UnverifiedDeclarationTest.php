<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the UnverifiedDeclaration value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(UnverifiedDeclaration::class)]
final class UnverifiedDeclarationTest extends TestCase
{
    /**
     * Test that the constructor sets every property, with no cause by default.
     *
     * @return void
     */
    public function testConstructorSetsProperties(): void
    {
        $declaration = new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class);

        self::assertSame(UserResource::class, $declaration->resourceClass);
        self::assertSame('name', $declaration->fieldKey);
        self::assertSame('a claim', $declaration->check);
        self::assertSame(User::class, $declaration->modelClass);
        self::assertNull($declaration->cause);
        self::assertSame('Connection refused', (new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class, 'Connection refused'))->cause);
    }

    /**
     * Test that the string form names the resource, the field and the claim.
     *
     * @return void
     */
    public function testToStringNamesTheClaimThatCouldNotBeVerified(): void
    {
        $declaration = new UnverifiedDeclaration(
            resourceClass: UserResource::class,
            fieldKey: 'name',
            check: 'an ordered index on table "users" leads with "name"',
            modelClass: User::class,
            cause: 'Connection refused',
        );

        self::assertSame(
            '[' . UserResource::class . '] Field "name": could not verify that an ordered index on table "users" leads with "name"',
            (string) $declaration,
        );
    }
}
