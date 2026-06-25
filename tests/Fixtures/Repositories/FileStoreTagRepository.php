<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture cacheable tag repository backed by the non-taggable file store.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Tag>
 */
final class FileStoreTagRepository extends ApiRepository
{
    use Cacheable;

    /** @var string|null Laravel cache store name. */
    protected ?string $cacheStoreName = 'file';

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
