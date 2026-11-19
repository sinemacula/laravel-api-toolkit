<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use Tests\Fixtures\Models\TypedWidget;
use Tests\Fixtures\Resources\TypedWidgetResource;

/**
 * Fixture controller documenting and rendering a typed widget.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TypedWidgetController
{
    /**
     * Show a typed widget with every field selected.
     *
     * @param  string  $widget
     * @return \Tests\Fixtures\Resources\TypedWidgetResource
     */
    #[ResponseSchema(TypedWidgetResource::class)]
    public function show(string $widget): TypedWidgetResource
    {
        return new TypedWidgetResource(TypedWidget::query()->findOrFail($widget), false, ':all');
    }
}
