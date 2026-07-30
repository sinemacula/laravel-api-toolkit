<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;
use Tests\Fixtures\Models\Organization;

/**
 * Fixture authorized controller excluded from every audience.
 *
 * Maps to the organization leaf resource under a blanket class-level
 * #[Undocumented] directive, so both the route and its schema are dropped from
 * every audience regardless of posture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Organization::class)]
#[Undocumented]
final class UndocumentedOrganizationController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
