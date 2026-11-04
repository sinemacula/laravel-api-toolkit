<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Routing\Attributes\Controllers\Authorize;
use SineMacula\ApiToolkit\Services\ServiceResult;

/**
 * Fixture controller outside the authorized controller hierarchy whose actions
 * carry the facts the error derivation reads.
 *
 * Used to prove that a non-resource action documents the ApiException
 * subclasses its throws tags name, ignoring any other throwable, and that an
 * authorization attribute alone implies the forbidden status.
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
     * Show a report guarded by an authorization attribute alone.
     *
     * @return void
     */
    #[Authorize('view-report')]
    public function report(): void {}
}
