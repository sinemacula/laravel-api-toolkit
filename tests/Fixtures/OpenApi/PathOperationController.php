<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\Operation;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller declaring operation ids and summaries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathOperationController
{
    /**
     * List the widgets under a declared id alone.
     *
     * @return void
     */
    #[Operation(id: 'widgets.listing')]
    public function index(): void {}

    /**
     * Show a widget under a declared id and summary.
     *
     * @return void
     */
    #[Operation(id: 'widgets.showcase', summary: 'Show a widget')]
    public function show(): void {}

    /**
     * Update a widget under a declared summary alone.
     *
     * @return void
     */
    #[Operation(summary: 'Update a widget')]
    #[RequestSchema(ArticleRequestInput::class)]
    public function update(): void {}

    /**
     * Report on the widgets, declaring nothing.
     *
     * @return void
     */
    public function report(): void {}
}
