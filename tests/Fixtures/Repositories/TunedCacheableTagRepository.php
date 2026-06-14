<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture cacheable tag repository that overrides every cache tuning property,
 * so the property-over-config precedence can be asserted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Tag>
 */
class TunedCacheableTagRepository extends ApiRepository
{
    use Cacheable;

    /** @var int Per-query cache duration in seconds. */
    protected int $cacheTtl = 120;

    /** @var int Reference-mode cache duration in seconds. */
    protected int $cacheReferenceTtl = 240;

    /** @var int The maximum cacheable row count. */
    protected int $cacheMaxRows = 50;

    /** @var int The maximum cacheable serialized byte size. */
    protected int $cacheMaxBytes = 2048;

    /** @var bool Whether the non-taggable key registry is enabled. */
    protected bool $cacheRegistryEnabled = false;

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
