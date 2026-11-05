<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource controller declaring success statuses.
 *
 * The index answers 206, the show its default 200, the update 204, and the
 * destroy 200; the store declares nothing, and every action carries the header
 * the controller declares.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
#[ResponseHeader('X-Request-Id')]
final class PathStatusUserController extends AuthorizedController
{
    /**
     * List the resources, answering with a partial collection.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::PARTIAL_CONTENT)]
    public function index(): void {}

    /**
     * Show a resource, declaring its default status.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::OK)]
    public function show(): void {}

    /**
     * Create a resource.
     *
     * @return void
     */
    public function store(): void {}

    /**
     * Update a resource, answering with no content.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::NO_CONTENT)]
    public function update(): void {}

    /**
     * Delete a resource, answering with a body.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::OK)]
    public function destroy(): void {}
}
