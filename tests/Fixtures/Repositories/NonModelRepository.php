<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\Repositories\Repository;
use stdClass;

/**
 * Repository fixture whose model class is not an Eloquent model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class NonModelRepository extends Repository
{
    /**
     * Return the model class.
     *
     * @return class-string
     */
    public function model(): string
    {
        return stdClass::class;
    }
}
