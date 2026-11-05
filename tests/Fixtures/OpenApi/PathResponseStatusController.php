<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Fixture non-resource controller declaring success statuses.
 *
 * The sign-out action answers 204 with a header of its own beside the one its
 * controller declares, the queue action 202 with a declared body, the reset
 * action 205, the store action 200, and the destroy action 200 with a declared
 * body; the bare action declares nothing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('X-Request-Id')]
final class PathResponseStatusController
{
    /**
     * Sign out of every session, answering with no content.
     *
     * @return void
     */
    #[ResponseHeader('Clear-Site-Data', 'The browsing data to clear.')]
    #[ResponseStatus(HttpStatus::NO_CONTENT)]
    public function destroyAll(): void {}

    /**
     * Queue a job, answering before it runs.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    #[ResponseStatus(HttpStatus::ACCEPTED)]
    public function queue(): void {}

    /**
     * Reset the client's view.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::RESET_CONTENT)]
    public function reset(): void {}

    /**
     * Create a resource, answering 200 rather than 201.
     *
     * @return void
     */
    #[ResponseStatus(HttpStatus::OK)]
    public function store(): void {}

    /**
     * Delete a resource, answering with what was deleted.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    #[ResponseStatus(HttpStatus::OK)]
    public function destroy(): void {}

    /**
     * Respond without declaring a status.
     *
     * @return void
     */
    public function bare(): void {}
}
