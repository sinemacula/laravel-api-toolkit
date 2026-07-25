<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Organization;

/**
 * Fixture authorized controller for the organization resource.
 *
 * The organization resource references no other resource, so it is a reachable
 * leaf: routing it pulls only its own schema into an audience, which the
 * exporter tests rely on to prove per-audience schema filtering.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathOrganizationController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = Organization::class;

    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Show a single resource.
     *
     * @return void
     */
    public function show(): void {}
}
