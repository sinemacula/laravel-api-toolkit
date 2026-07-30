<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GatesTrashedVisibility;
use SineMacula\Http\Enums\HttpMethod;
use Tests\TestCase;

/**
 * Tests for the GatesTrashedVisibility trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(GatesTrashedVisibility::class)]
final class GatesTrashedVisibilityTest extends TestCase
{
    /**
     * Test that a resource using the trait denies trashed visibility by
     * default.
     *
     * @return void
     */
    public function testDeniesTrashedVisibilityByDefault(): void
    {
        $resource = new class {
            use GatesTrashedVisibility;
        };

        $request = Request::create('/test', HttpMethod::GET->getVerb());

        self::assertFalse($resource::allowsTrashed($request));
    }
}
