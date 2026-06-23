<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture cacheable tag repository with a one-row size guard ceiling.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Tag>
 */
final class SizeGuardedTagRepository extends ApiRepository
{
    use Cacheable;

    /** @var int|null Size guard row ceiling. */
    protected ?int $cacheMaxRows = 1;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Tag>
     */
    #[\Override]
    public function model(): string
    {
        return Tag::class;
    }
}
