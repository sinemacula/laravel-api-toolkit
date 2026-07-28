<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

/**
 * Fixture controller that is not an authorized controller.
 *
 * Used to prove that a route handled by a controller outside the authorized
 * controller hierarchy is still documented as a non-resource operation, and
 * that a non-REST action name is documented just the same.
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

    /**
     * A non-REST action documented as a non-resource operation.
     *
     * @return void
     */
    public function report(): void {}
}
