<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Transactional service wrapped in the given concerns, whose body does nothing.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, true>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConcernedService extends Service
{
    /**
     * Constructor.
     *
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>  $wrappedIn
     */
    public function __construct(

        /** The concerns the run is wrapped in */
        private readonly array $wrappedIn,
    ) {
        parent::__construct(new ArrayInput([]));
    }

    /**
     * Return the ordered list of custom concern classes.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    #[\Override]
    protected function concerns(): array
    {
        return $this->wrappedIn;
    }

    /**
     * Handle the main execution of the service.
     *
     * @return true
     */
    #[\Override]
    protected function handle(): mixed
    {
        return true;
    }
}
