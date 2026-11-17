<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Article;

/**
 * Fixture cacheable article repository over a soft-deleting model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Article>
 */
final class CacheableArticleRepository extends ApiRepository
{
    use Cacheable;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Article>
     */
    #[\Override]
    public function model(): string
    {
        return Article::class;
    }
}
