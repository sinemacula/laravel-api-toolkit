<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Post;

/**
 * Fixture dummy repository for testing repository resolution.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class DummyRepository extends ApiRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    public function model(): string
    {
        return Post::class;
    }
}
