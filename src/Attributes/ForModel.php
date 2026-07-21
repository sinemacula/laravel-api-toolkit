<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Attributes;

/**
 * Declare the Eloquent model an API resource presents.
 *
 * Resources carrying this attribute are discovered at boot from the configured
 * resource paths and compiled into the model to resource map, so the binding
 * lives on the resource itself rather than in a hand-maintained config entry.
 * The attribute may be repeated to bind one resource to several models. An
 * explicit `resource_map` entry for the same model always wins, acting as the
 * canonical-resource tiebreak when a model has more than one resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class ForModel
{
    /**
     * Create a new resource model binding.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    public function __construct(

        /** The model class this resource presents. */
        public string $model,
    ) {}
}
