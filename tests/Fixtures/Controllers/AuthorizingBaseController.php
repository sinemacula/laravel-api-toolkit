<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use SineMacula\ApiToolkit\Http\Routing\Attributes\SkipAuthorization;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;

/**
 * Fixture base controller whose class-level gate and deliberately public action
 * its subclasses inherit.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Authorize('update', 'user', except: ['archive', 'publish', 'ping'])]
abstract class AuthorizingBaseController extends AuthorizedController
{
    /**
     * Answer a liveness probe, deliberately unauthorized.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[SkipAuthorization]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
