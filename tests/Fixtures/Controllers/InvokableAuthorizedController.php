<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture single-action authorized controller declaring no authorization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class InvokableAuthorizedController extends AuthorizedController
{
    /**
     * Notify a user.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function __invoke(User $user): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
