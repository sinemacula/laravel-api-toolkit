<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\User;

/**
 * Fixture cacheable user repository.
 *
 * Mirrors CacheableTagRepository but over the User model, so per-query caching
 * can be exercised across the HTTP surface for users.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\User>
 */
final class CacheableUserRepository extends ApiRepository
{
    use Cacheable;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\User>
     */
    #[\Override]
    public function model(): string
    {
        return User::class;
    }
}
