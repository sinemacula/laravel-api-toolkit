<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use Tests\Fixtures\Models\User;

/**
 * Fixture deferrable user repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\User>
 */
class DeferrableUserRepository extends ApiRepository
{
    use Deferrable;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\User>
     */
    public function model(): string
    {
        return User::class;
    }
}
