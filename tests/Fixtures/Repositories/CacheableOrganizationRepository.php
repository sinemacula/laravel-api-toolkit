<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Organization;

/**
 * Fixture cacheable organization repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Organization>
 */
final class CacheableOrganizationRepository extends ApiRepository
{
    use Cacheable;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Organization>
     */
    #[\Override]
    public function model(): string
    {
        return Organization::class;
    }
}
