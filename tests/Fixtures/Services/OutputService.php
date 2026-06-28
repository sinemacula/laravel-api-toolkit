<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service that returns typed output.
 *
 * Demonstrates that handle() returns a non-boolean output value and that the
 * service reads a typed property from $this->input (AC-11). Used by
 * ServiceRunnerTest and ServiceTest to verify the output threads through
 * ServiceResult::output().
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, array{message: string}>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OutputService extends Service
{
    /**
     * Return a typed output array built from the input.
     *
     * @return array{message: string}
     */
    #[\Override]
    protected function handle(): mixed
    {
        $data = $this->input->toArray();

        return ['message' => isset($data['message']) && is_string($data['message']) ? $data['message'] : 'default'];
    }
}
