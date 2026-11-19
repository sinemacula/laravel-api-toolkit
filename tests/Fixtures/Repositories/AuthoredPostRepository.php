<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\AuthoredPost;

/**
 * Fixture authored post repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\AuthoredPost>
 */
final class AuthoredPostRepository extends ApiRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\AuthoredPost>
     */
    #[\Override]
    public function model(): string
    {
        return AuthoredPost::class;
    }
}
