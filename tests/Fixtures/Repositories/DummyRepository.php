<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\User;

class DummyRepository extends ApiRepository
{
    public function model(): string
    {
        return User::class;
    }
}
