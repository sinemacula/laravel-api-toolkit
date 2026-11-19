<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\ImportRow;

/**
 * Fixture import row repository, over a table without its model's key column.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\ImportRow>
 */
final class ImportRowRepository extends ApiRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\ImportRow>
     */
    #[\Override]
    public function model(): string
    {
        return ImportRow::class;
    }
}
