<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource controller declaring a body for a no-content status.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathStatusConflictController extends AuthorizedController
{
    /**
     * Update a resource, answering with no content yet declaring a body.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    #[ResponseStatus(HttpStatus::NO_CONTENT)]
    public function update(): void {}

    /**
     * Reset a resource, answering with no content yet declaring a body.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    #[ResponseStatus(HttpStatus::RESET_CONTENT)]
    public function reset(): void {}
}
