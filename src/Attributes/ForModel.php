<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Attributes;

/**
 * Declare the Eloquent model an API resource presents.
 *
 * Repeatable; an explicit resource_map entry for the same model always wins.
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

        /** The model class this resource presents */
        public string $model,
    ) {}
}
