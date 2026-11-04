<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource controller redeclaring, under a different case, the
 * total-count header the exporter emits on a collection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathHeaderCollisionController extends AuthorizedController
{
    /**
     * List the resources, redeclaring the total-count header.
     *
     * @return void
     */
    #[ResponseHeader('TOTAL-COUNT', 'The total number of records.', ['type' => 'integer'])]
    public function index(): void {}
}
