<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\User;

/**
 * Fixture cacheable user repository operating in whole-table reference mode.
 *
 * Opts into the whole-table reference cache so a repeat read serves the
 * snapshot with zero queries, while a criteria-composed read falls through to
 * the database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\User>
 */
final class ReferenceCacheUserRepository extends ApiRepository
{
    use Cacheable;

    /** @var bool Serve the whole table from a single cached snapshot */
    protected bool $cacheReferenceTable = true;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\User>
     */
    #[\Override]
    public function model(): string
    {
        return User::class;
    }
}
