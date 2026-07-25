<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

/**
 * Fixture controller that is not an authorized controller.
 *
 * Used to prove that routes handled by a controller outside the authorized
 * controller hierarchy are skipped by the path builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathPlainController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
