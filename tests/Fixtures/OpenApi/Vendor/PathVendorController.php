<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi\Vendor;

/**
 * Fixture controller in a distinct namespace used as a stand-in for a route
 * defined by a framework or tooling package.
 *
 * Its namespace is added to the exclusion blocklist by the path builder tests
 * to prove a route defined under a blocklisted namespace never contributes an
 * operation, while a controller in the ordinary fixture namespace is kept.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathVendorController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
