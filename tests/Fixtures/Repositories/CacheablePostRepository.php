<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Post;

/**
 * Fixture cacheable post repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Post>
 */
final class CacheablePostRepository extends ApiRepository
{
    use Cacheable;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Post>
     */
    #[\Override]
    public function model(): string
    {
        return Post::class;
    }
}
