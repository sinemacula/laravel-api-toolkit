<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Models\User;

/**
 * Fixture service that creates a user from its input and returns the model.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, \Tests\Fixtures\Models\User>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CreatingUserService extends Service
{
    /**
     * Create the user.
     *
     * @return \Tests\Fixtures\Models\User
     */
    #[\Override]
    protected function handle(): User
    {
        return User::create($this->input->toArray());
    }
}
