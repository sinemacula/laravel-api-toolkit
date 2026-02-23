<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\User;

/**
 * Fixture user repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class UserRepository extends ApiRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    public function model(): string
    {
        return User::class;
    }
}
