<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Measurement;

/**
 * Fixture measurement repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Measurement>
 */
final class MeasurementRepository extends ApiRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Measurement>
     */
    #[\Override]
    public function model(): string
    {
        return Measurement::class;
    }
}
