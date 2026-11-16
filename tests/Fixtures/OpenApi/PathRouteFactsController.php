<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Routing\Attributes\Controllers\Authorize;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Fixture controller outside the authorized controller hierarchy whose actions
 * carry the facts the error derivation reads.
 *
 * Used to prove that a non-resource action documents the ApiException
 * subclasses its throws tags name, ignoring any other throwable and naming an
 * exception tagged twice once, that an exception without an error code has no
 * example, and that an authorization attribute alone implies the forbidden
 * status.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathRouteFactsController
{
    /**
     * Sign in by running a service and rethrowing its failure.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceResult<mixed>  $result
     * @return mixed
     *
     * @throws \Throwable
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnauthenticatedException
     * @throws \SineMacula\ApiToolkit\Exceptions\TooManyRequestsException
     * @throws \SineMacula\ApiToolkit\Exceptions\ForbiddenException
     * @throws \SineMacula\ApiToolkit\Exceptions\TokenMismatchException
     * @throws \Tests\Fixtures\OpenApi\StatuslessApiException
     */
    public function signIn(ServiceResult $result): mixed
    {
        return $result->throw();
    }

    /**
     * Retry a request, naming one exception under two tags.
     *
     * @param  bool  $conflict
     * @return never
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\TooManyRequestsException
     * @throws \SineMacula\ApiToolkit\Exceptions\ConflictException|\SineMacula\ApiToolkit\Exceptions\TooManyRequestsException
     */
    public function retry(bool $conflict): never
    {
        throw $conflict ? new ConflictException : new TooManyRequestsException;
    }

    /**
     * Spend from a request budget, throwing an exception under its own code.
     *
     * @return never
     *
     * @throws \Tests\Fixtures\OpenApi\SlowDownException
     */
    public function spend(): never
    {
        throw new SlowDownException;
    }

    /**
     * Release a hold, throwing an exception without an error code.
     *
     * @return never
     *
     * @throws \Tests\Fixtures\OpenApi\CodelessApiException
     */
    public function release(): never
    {
        throw new CodelessApiException;
    }

    /**
     * Show a report guarded by an authorization attribute alone.
     *
     * @return void
     */
    #[Authorize('view-report')]
    public function report(): void {}
}
