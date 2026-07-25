<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller excluded from the public audience.
 *
 * Used to prove that a route failing the audience membership check is omitted
 * from that audience's paths even though it is otherwise documentable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[NotDocumentedIn('public')]
final class PathExcludedController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = User::class;

    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
