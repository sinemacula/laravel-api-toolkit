<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller whose custom actions are gated by class-level
 * attributes, its own and the one it inherits.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Authorize('delete', 'user', only: ['archive'])]
#[AuthorizesResource(User::class)]
final class ClassAuthorizedController extends AuthorizingBaseController
{
    /**
     * Archive a user, gated by the class's delete ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function archive(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Approve a user, gated by the inherited update ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function approve(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Publish a user, outside both class-level gates.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function publish(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
