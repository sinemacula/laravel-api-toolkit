<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use Tests\Fixtures\Models\Organization;

/**
 * Fixture authorized controller opted into the partner audience.
 *
 * Maps to the organization leaf resource and carries a class-level
 * #[DocumentedIn('partner')] directive, so an allowlist partner audience
 * documents both the route and its schema while an allowlist default audience
 * that names nothing leaves it out.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Organization::class)]
#[DocumentedIn('partner')]
final class PartnerOrganizationController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
