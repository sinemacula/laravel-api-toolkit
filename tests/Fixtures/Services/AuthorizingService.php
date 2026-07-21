<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Auth\Access\AuthorizationException;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;

/**
 * Fixture service whose authorization hook always denies.
 *
 * The authorize() hook runs before the lock and transaction and throws an
 * AuthorizationException, which the runner captures as a failure so handle()
 * never runs. Over HTTP, run()->throw() rethrows it and the exception handler
 * renders the forbidden envelope.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, true>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AuthorizingService extends Service
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     */
    public function __construct(ServiceInput $input = new ArrayInput([]))
    {
        parent::__construct($input);
    }

    /**
     * Deny the current actor.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[\Override]
    protected function authorize(): void
    {
        throw new AuthorizationException('The actor is not authorized to perform this action.');
    }

    /**
     * Handles the main execution of the service.
     *
     * @return true
     */
    #[\Override]
    protected function handle(): mixed
    {
        return true;
    }
}
