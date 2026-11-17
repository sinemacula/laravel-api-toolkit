<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\TenantUser;

/**
 * Fixture whole-table reference repository over a tenant-scoped model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\TenantUser>
 */
final class ReferenceCacheTenantUserRepository extends ApiRepository
{
    use Cacheable;

    /** @var bool Serve each tenant's rows from a cached snapshot */
    protected bool $cacheReferenceTable = true;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\TenantUser>
     */
    #[\Override]
    public function model(): string
    {
        return TenantUser::class;
    }
}
