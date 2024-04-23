<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The base API resource collection.
 *
 * This handles dynamic field filtering based on API query parameters. It
 * leverages a global query parser to determine which fields should be included
 * in the response.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class ApiResourceCollection extends AnonymousResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return $this->collection->map(fn (ApiResource $item) => $item->resolve($request))->all();
    }
}
