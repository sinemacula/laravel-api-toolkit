<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;

/**
 * Fixture controller declaring a header for every action it handles.
 *
 * Redeclares a header its parent declares, and the show action redeclares the
 * controller's header under a different case, so the nearest declaration can be
 * shown to win.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('X-Request-Id', 'The identifier the controller assigns.')]
final class PathClassHeaderController extends PathHeaderBaseController
{
    /**
     * List the records, declaring no header of its own.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Show a record, redeclaring the controller's header.
     *
     * @return void
     */
    #[ResponseHeader('ETag', 'The entity tag of the record.')]
    #[ResponseHeader('x-request-id', 'The identifier the action assigns.')]
    public function show(): void {}
}
