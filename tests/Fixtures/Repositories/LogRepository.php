<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Log;

/**
 * Fixture log repository backing the JSON-column containment tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Tests\Fixtures\Models\Log>
 */
final class LogRepository extends ApiRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Fixtures\Models\Log>
     */
    #[\Override]
    public function model(): string
    {
        return Log::class;
    }
}
