<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\Attributes\CustomAuthorization;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller whose custom actions each declare their
 * authorization differently, or not at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class CustomActionController extends AuthorizedController
{
    /**
     * Publish a user, declaring no authorization.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function publish(User $user): JsonResponse
    {
        $user->update(['name' => 'Published']);

        return new JsonResponse(['data' => []]);
    }

    /**
     * Update a user. Guarded by the update ability unless the route removes it.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(User $user): JsonResponse
    {
        $user->update(['name' => 'Updated']);

        return new JsonResponse(['data' => []]);
    }

    /**
     * Approve a user, gated by the denied update ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    #[Authorize('update', 'user')]
    public function approve(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Archive a user, gated by the allowed delete ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    #[Authorize('delete', 'user')]
    public function archive(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Lock a user, authorizing inline against the denied update ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[CustomAuthorization]
    public function lock(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        return new JsonResponse(['data' => []]);
    }
}
