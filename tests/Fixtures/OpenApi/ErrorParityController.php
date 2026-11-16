<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Tests\Fixtures\Models\User;

/**
 * Fixture controller whose actions fail the way the documented route facts say
 * they can.
 *
 * The show action binds a model, so a missing record is answered as not found,
 * and the fail action throws an exception the handler does not map.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ErrorParityController
{
    /**
     * Show a bound user.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return void
     */
    public function show(User $user): void {}

    /**
     * Fail with an exception the handler does not map.
     *
     * @return never
     *
     * @throws \RuntimeException
     */
    public function fail(): never
    {
        throw new \RuntimeException('The parity fixture failed.');
    }
}
