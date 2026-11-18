<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Contracts\Events\Dispatcher;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Input\StorePayload;

/**
 * Fixture service taking its input under another name after an injected
 * dependency, and returning the input it was given.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\Tests\Fixtures\Input\StorePayload, \Tests\Fixtures\Input\StorePayload>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RenamedInputService extends Service
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  \Tests\Fixtures\Input\StorePayload  $payload
     */
    public function __construct(

        /** The dispatcher the container injects. */
        public readonly Dispatcher $events,

        // The input, handed to the parent constructor.
        StorePayload $payload,
    ) {
        parent::__construct($payload);
    }

    /**
     * Return the input the service was constructed with.
     *
     * @return \Tests\Fixtures\Input\StorePayload
     */
    #[\Override]
    protected function handle(): mixed
    {
        return $this->input;
    }
}
