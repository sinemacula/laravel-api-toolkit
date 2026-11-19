<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Models\Document;

/**
 * Fixture resource controller over a versioned model whose actions replace or
 * empty the resource contract, or redeclare its entity tag.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Document::class)]
final class PathDocumentDeclarationsController extends AuthorizedController
{
    /**
     * Show a single resource, redeclaring its entity tag.
     *
     * @return void
     */
    #[ResponseHeader('etag', 'The entity tag.')]
    public function show(): void {}

    /**
     * Create a resource, answering with another shape.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    public function store(): void {}

    /**
     * Update a resource, answering with no content.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::NO_CONTENT)]
    public function update(): void {}
}
