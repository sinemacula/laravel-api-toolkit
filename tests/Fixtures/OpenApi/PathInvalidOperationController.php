<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\Operation;

/**
 * Fixture controller excluded from the public audience, declaring an invalid
 * operation directive.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[NotDocumentedIn('public')]
final class PathInvalidOperationController
{
    /**
     * List the resources under a directive that declares nothing.
     *
     * @return void
     */
    #[Operation]
    public function index(): void {}
}
