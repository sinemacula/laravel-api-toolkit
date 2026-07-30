<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;

/**
 * Tests for the SchemaNameCollisionGuard.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaNameCollisionGuard::class)]
final class SchemaNameCollisionGuardTest extends TestCase
{
    /**
     * Test that a first claim on a name is recorded against its source.
     *
     * @return void
     */
    public function testFirstClaimIsRecorded(): void
    {
        $claimed = [];

        (new SchemaNameCollisionGuard)->claim($claimed, 'User', 'App\UserResource');

        self::assertSame(['User' => 'App\UserResource'], $claimed);
    }

    /**
     * Test that re-claiming a name with the same source is a no-op, so a schema
     * shared across sources stays a single claim.
     *
     * @return void
     */
    public function testSameSourceReclaimIsANoOp(): void
    {
        $claimed = ['User' => 'App\UserResource'];

        (new SchemaNameCollisionGuard)->claim($claimed, 'User', 'App\UserResource');

        self::assertSame(['User' => 'App\UserResource'], $claimed);
    }

    /**
     * Test that claiming a name held by a different source fails loud, naming
     * both the existing and the colliding source.
     *
     * @return void
     */
    public function testDifferentSourceThrowsNamingBoth(): void
    {
        $claimed = ['User' => 'App\UserResource'];

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage('App\UserResource');
        $this->expectExceptionMessage('App\Enums\User');

        (new SchemaNameCollisionGuard)->claim($claimed, 'User', 'App\Enums\User');
    }
}
