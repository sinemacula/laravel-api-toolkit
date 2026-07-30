<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

/**
 * Fixture single-action invokable controller.
 *
 * Handles its route through __invoke rather than a REST action, so the path
 * builder documents it as a non-resource operation carrying the shared
 * x-undocumented success envelope.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathInvokableController
{
    /**
     * Handle the request.
     *
     * @return void
     */
    public function __invoke(): void {}
}
