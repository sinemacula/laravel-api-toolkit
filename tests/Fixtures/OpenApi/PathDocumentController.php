<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\PreconditionFailedException;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\Document;

/**
 * Fixture resource controller over a versioned model whose update checks an
 * If-Match precondition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Document::class)]
final class PathDocumentController extends AuthorizedController
{
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

    /**
     * Create a resource.
     *
     * @return void
     */
    public function store(): void {}

    /**
     * Update a resource the client last read.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionFailedException
     */
    public function update(): void
    {
        throw new PreconditionFailedException;
    }

    /**
     * Delete a resource.
     *
     * @return void
     */
    public function destroy(): void {}
}
