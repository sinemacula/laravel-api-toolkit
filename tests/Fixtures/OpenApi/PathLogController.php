<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Log;

/**
 * Fixture authorized controller for the log resource.
 *
 * The log resource references no other resource, so routing its index pulls
 * only its own schema into the document, which the query-surface contract
 * relies on to reach a resource declaring every capability.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Log::class)]
final class PathLogController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
