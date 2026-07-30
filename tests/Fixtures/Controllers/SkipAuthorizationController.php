<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\Attributes\SkipAuthorization;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller exercising the #[SkipAuthorization] marker.
 *
 * The attribute carries no class-level except list, so the sole ungated action
 * is the one marked with #[SkipAuthorization].
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class SkipAuthorizationController extends AuthorizedController
{
    /**
     * Update a user. Guarded by the update ability.
     *
     * Declared before the skipped action so the marker discovery must scan past
     * an unmarked action rather than stopping at the first one.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Show a user. Left ungated by the marker.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[SkipAuthorization]
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
