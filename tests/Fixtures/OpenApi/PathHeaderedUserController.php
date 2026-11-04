<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource controller declaring headers beside the ones the exporter
 * emits for a resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathHeaderedUserController extends AuthorizedController
{
    /**
     * List the resources, declaring a header beside the total count.
     *
     * @return void
     */
    #[ResponseHeader('Link', 'The links to the neighbouring pages.')]
    public function index(): void {}

    /**
     * Delete a resource, declaring a header on its empty response.
     *
     * @return void
     */
    #[ResponseHeader('X-Deleted-At', 'When the resource was deleted.', ['type' => 'string', 'format' => 'date-time'])]
    public function destroy(): void {}
}
