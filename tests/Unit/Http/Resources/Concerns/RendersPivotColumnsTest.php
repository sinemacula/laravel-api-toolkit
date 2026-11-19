<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RendersPivotColumns;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Resources\PivotPayloadResource;
use Tests\TestCase;

/**
 * Tests for the RendersPivotColumns trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(RendersPivotColumns::class)]
final class RendersPivotColumnsTest extends TestCase
{
    /**
     * Test that the payload reads each present column through the pivot's
     * casts, a null included, omits an absent one and reduces a pure enum to
     * its name.
     *
     * @return void
     */
    public function testPayloadReadsEachPresentColumnThroughThePivotsCasts(): void
    {
        $pivot = new class extends Pivot {
            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            #[\Override]
            protected function casts(): array
            {
                return ['platform' => Platform::class, 'status' => UserStatus::class];
            }
        };

        $tag      = (new Tag)->setRelation('membership', $pivot->setRawAttributes(['tag_id' => 1, 'platform' => 'MOBILE_APP', 'status' => 'active', 'note' => null]));
        $resource = new PivotPayloadResource($tag);

        self::assertSame($resource, $resource->withPivot('membership', ['platform', 'status', 'note', 'joined_at']));
        self::assertSame(['pivot' => ['platform' => 'mobile_app', 'status' => UserStatus::ACTIVE, 'note' => null]], $resource->payload());
    }

    /**
     * Test that there is no payload without a declared pivot, a loaded pivot
     * model, a model resource or a declared column the pivot carries.
     *
     * @return void
     */
    public function testNoPayloadWithoutADeclaredColumnToRender(): void
    {
        $tag = (new Tag)->setRelation('pivot', (new Pivot)->setRawAttributes(['tag_id' => 1, 'role' => 'primary']));

        self::assertSame([], (new PivotPayloadResource($tag))->payload());
        self::assertSame([], (new PivotPayloadResource(new Tag))->withPivot('pivot', ['role'])->payload());
        self::assertSame([], (new PivotPayloadResource((new Tag)->setRelation('pivot', null)))->withPivot('pivot', ['role'])->payload());
        self::assertSame([], (new PivotPayloadResource(['pivot' => ['role' => 'primary']]))->withPivot('pivot', ['role'])->payload());
        self::assertSame([], (new PivotPayloadResource($tag))->withPivot('pivot', ['rank'])->payload());
    }
}
