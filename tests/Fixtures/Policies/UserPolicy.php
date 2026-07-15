<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fixture user policy.
 *
 * Denies the create ability so an authorized controller action guarded by the
 * gate renders a forbidden response.
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
}
