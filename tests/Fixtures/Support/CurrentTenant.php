<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

/**
 * Fixture tenant bound in the container for the duration of a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CurrentTenant
{
    /**
     * Create a new current tenant.
     *
     * @param  int  $organizationId
     * @return void
     */
    public function __construct(

        /** The organization whose rows the tenant may see. */
        public int $organizationId,
    ) {}
}
