<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that reads a typed property straight off its input.
 *
 * handle() reads $this->input->city with no narrowing, so static analysis of
 * this fixture proves the input is typed as the declared input template.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\Tests\Fixtures\Input\SampleInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TypedInputService extends Service
{
    /**
     * Return the city from the typed input.
     *
     * @return string
     */
    #[\Override]
    protected function handle(): mixed
    {
        return $this->input->city;
    }
}
