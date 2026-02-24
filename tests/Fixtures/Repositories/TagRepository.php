<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture tag repository for testing MorphToMany relation resolution.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Tag>
 */
class TagRepository extends ApiRepository
{
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
