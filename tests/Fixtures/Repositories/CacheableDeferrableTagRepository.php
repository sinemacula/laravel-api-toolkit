<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture repository that uses both the Cacheable and Deferrable concerns, to
 * prove they coexist on one repository without a boot() collision.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Tag>
 */
final class CacheableDeferrableTagRepository extends ApiRepository
{
    use Cacheable;
    use Deferrable;

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
