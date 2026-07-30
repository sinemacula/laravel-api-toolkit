<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Fixtures\Models\User;

/**
 * Fixture user policy.
 *
 * Grants and denies deterministically so an authorized controller action
 * guarded by the gate renders either a forbidden response or passes through:
 * the create and update abilities are denied, while delete is allowed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UserPolicy
{
    /**
     * Determine whether the actor may create users.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @return \Illuminate\Auth\Access\Response
     *
     * @SuppressWarnings("php:S1172")
     */
    public function create(Authenticatable $actor): Response
    {
        return Response::deny();
    }

    /**
     * Determine whether the actor may update the given user.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Auth\Access\Response
     *
     * @SuppressWarnings("php:S1172")
     */
    public function update(Authenticatable $actor, User $user): Response
    {
        return Response::deny();
    }

    /**
     * Determine whether the actor may delete the given user.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Illuminate\Auth\Access\Response
     *
     * @SuppressWarnings("php:S1172")
     */
    public function delete(Authenticatable $actor, User $user): Response
    {
        return Response::allow();
    }
}
