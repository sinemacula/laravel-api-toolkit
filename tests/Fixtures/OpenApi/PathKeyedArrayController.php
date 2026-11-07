<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller whose request rules address array fields by named keys.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathKeyedArrayController
{
    /**
     * Create a resource from rules addressing array fields by named keys.
     *
     * @return void
     */
    #[RequestSchema(KeyedArrayRequestInput::class)]
    public function store(): void {}
}
