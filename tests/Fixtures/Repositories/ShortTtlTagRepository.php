<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture cacheable tag repository with a short TTL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Tag>
 */
class ShortTtlTagRepository extends ApiRepository
{
    use Cacheable;

    /** @var int Cache duration in seconds. */
    protected int $cacheTtl = 5;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Tag>
     */
    public function model(): string
    {
        return Tag::class;
    }
}
