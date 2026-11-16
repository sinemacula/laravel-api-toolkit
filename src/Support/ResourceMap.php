<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Reads the configured resource map, refusing a binding of a non-model or a
 * missing class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResourceMap
{
    /**
     * Return the configured model to resource bindings, or an empty map when
     * none are configured.
     *
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>
     *
     * @throws \LogicException
     */
    public static function configured(): array
    {
        $configured = Config::get('api-toolkit.resources.resource_map');

        if (!is_array($configured)) {
            return [];
        }

        $map = [];

        foreach ($configured as $model => $resource) {

            if (!is_string($model) || !is_subclass_of($model, Model::class) || !is_string($resource) || !class_exists($resource)) {
                $label = is_string($resource) ? $resource : get_debug_type($resource);

                throw new \LogicException(sprintf('The resource map must bind an existing Eloquent model class to an existing resource class, [%s => %s] given', $model, $label));
            }

            $map[$model] = $resource;
        }

        return $map;
    }
}
