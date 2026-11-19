<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\RelationEdge;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Resources\OrganizationResource;

/**
 * Tests for the relation edge.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationEdge::class)]
final class RelationEdgeTest extends TestCase
{
    /**
     * Test that a relation governed by a resource exposes that resource.
     *
     * @return void
     */
    public function testExposesTheResourceGoverningTheRelation(): void
    {
        $edge = new RelationEdge('organization', Organization::class, false, OrganizationResource::class);

        self::assertSame('organization', $edge->relation);
        self::assertSame(Organization::class, $edge->model);
        self::assertFalse($edge->polymorphic);
        self::assertSame(OrganizationResource::class, $edge->resource);
    }

    /**
     * Test that a polymorphic relation, governed per concrete type, exposes no
     * resource.
     *
     * @return void
     */
    public function testPolymorphicRelationExposesNoResource(): void
    {
        $edge = new RelationEdge('noteable', Note::class, true, null);

        self::assertTrue($edge->polymorphic);
        self::assertNull($edge->resource);
    }
}
