<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\ChildProjection;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the child projection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ChildProjection::class)]
final class ChildProjectionTest extends TestCase
{
    /**
     * Test that a projection read only by its child resource exposes that
     * resource and the fields it renders.
     *
     * @return void
     */
    public function testExposesTheChildResourceAndTheFieldsItRenders(): void
    {
        $projection = new ChildProjection(OrganizationResource::class, ['id', 'name'], false);

        self::assertSame(OrganizationResource::class, $projection->resource);
        self::assertSame(['id', 'name'], $projection->fields);
        self::assertFalse($projection->opaque);
    }

    /**
     * Test that a projection something else also reads is marked opaque.
     *
     * @return void
     */
    public function testProjectionReadBeyondTheChildResourceIsOpaque(): void
    {
        $projection = new ChildProjection(OrganizationResource::class, [], true);

        self::assertSame([], $projection->fields);
        self::assertTrue($projection->opaque);
    }

    /**
     * Test that a projection plan maps each path to the child resource that
     * renders it.
     *
     * @return void
     */
    public function testPlanMapsEachPathToItsChildResource(): void
    {
        $plan = [
            'organization'       => new ChildProjection(OrganizationResource::class, ['id'], false),
            'organization.users' => new ChildProjection(UserResource::class, [], true),
        ];

        self::assertSame(['organization' => OrganizationResource::class, 'organization.users' => UserResource::class], ChildProjection::resourcesOf($plan));
    }
}
