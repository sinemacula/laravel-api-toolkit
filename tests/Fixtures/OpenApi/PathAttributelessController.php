<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;

/**
 * Fixture authorized controller declaring no resource attribute.
 *
 * Omits the #[AuthorizesResource] declaration every authorized controller is
 * expected to carry, so its resource model cannot be resolved and the path
 * builder must fall back to the non-resource operation rather than surfacing
 * the misconfiguration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathAttributelessController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
