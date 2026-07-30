<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\Tag;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller carrying every operation facet at once.
 *
 * A single realistic write endpoint: the class-level #[Tag] fixes the group,
 * the store action names a real rules source through #[RequestSchema] and
 * documents a ConflictException throw, and the model maps to a registered
 * resource so the created-resource response references a concrete component.
 * Registering its POST route under an auth guard lets the exporter be driven
 * against the requestBody, response, security, error, and tag facets together.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
#[Tag('Billing')]
final class BillingController extends AuthorizedController
{
    /**
     * Create a resource from a validated request body.
     *
     * @return never
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ConflictException
     */
    #[RequestSchema(ArticleRequestInput::class)]
    public function store(): never
    {
        throw new ConflictException;
    }
}
