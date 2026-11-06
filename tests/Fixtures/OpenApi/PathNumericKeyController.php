<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller whose request rules key fields by list indices.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathNumericKeyController
{
    /**
     * Create a resource from rules mixing indexed and named keys.
     *
     * @return void
     */
    #[RequestSchema(NumericKeyRequestInput::class)]
    public function store(): void {}

    /**
     * Create a resource from rules keyed only by indices.
     *
     * @return void
     */
    #[RequestSchema(IndexedRequestInput::class)]
    public function indexed(): void {}
}
