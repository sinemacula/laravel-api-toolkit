<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;

/**
 * Fixture controller documented only in the internal audience.
 *
 * Combines a blanket Undocumented with an internal inclusion, the canonical
 * "only in x" expression: excluded everywhere except the named audience.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[DocumentedIn('internal')]
#[Undocumented]
final class OnlyInternalController
{
    /**
     * Action inheriting the class-level "only internal" directives.
     *
     * @return void
     */
    public function none(): void {}
}
