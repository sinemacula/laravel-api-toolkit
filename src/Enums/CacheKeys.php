<?php

namespace SineMacula\ApiToolkit\Enums;

use Illuminate\Support\Facades\Config;

/**
 * Define the keys used for the cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CacheKeys: string
{
    // Store the casts for each model used in the repositories
    case REPOSITORY_MODEL_CASTS = 'repository-model-casts:%s';

    // Store the columns associated with each model
    case MODEL_SCHEMA_COLUMNS = 'model-schema-columns:%s';

    // Store the relations associated with each model
    case MODEL_RELATIONS = 'model-relations:%s:%s';

    // Store the resource associated with each model
    case MODEL_RESOURCES = 'model-resources:%s';

    // Store the eager loads associated with each model
    case MODEL_EAGER_LOADS = 'model-eager-loads:%s:%s';

    // Store the model relation instances associated with each model
    case MODEL_RELATION_INSTANCES = 'model-relation-instances:%s:%s';

    /**
     * Resolves the cache key with the necessary prefix and replaces any
     * placeholders.
     *
     * @param  array<int, mixed>  $replacements
     * @return string
     */
    public function resolveKey(array $replacements = []): string
    {
        $prefix = (string) Config::get('api-toolkit.cache.prefix', 'sm-api-toolkit');

        $key = $prefix . ':' . $this->value;

        if (!empty($replacements)) {
            $key = vsprintf($key, $replacements);
        }

        return $key;
    }
}
