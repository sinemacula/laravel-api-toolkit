<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\Repositories\Repository;
use Tests\Fixtures\Record;

/**
 * Record repository fixture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class RecordRepository extends Repository
{
    /**
     * Return the model class.
     *
     * @return class-string
     */
    public function model(): string
    {
        return Record::class;
    }
}
