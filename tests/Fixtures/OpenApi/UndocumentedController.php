<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;

/**
 * Fixture controller excluded from every audience at the class level.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Undocumented]
final class UndocumentedController
{
    /**
     * Action inheriting the blanket class-level exclusion.
     *
     * @return void
     */
    public function none(): void {}
}
