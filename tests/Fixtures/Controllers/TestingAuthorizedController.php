<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller for testing authorization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class, parameter: 'user', except: ['index', 'show'])]
final class TestingAuthorizedController extends AuthorizedController
{
    /**
     * List users. Excluded from the authorization guard.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Show a user. Excluded from the authorization guard.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function show(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Create a user. Guarded by the create ability.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(): JsonResponse
    {
        return new JsonResponse(['data' => []], 201);
    }

    /**
     * Update a user. Guarded by the update ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function update(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Delete a user. Guarded by the delete ability.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function destroy(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
