<?php

declare(strict_types = 1);

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

    // Store the per-column type/nullability definitions for each model
    case MODEL_SCHEMA_COLUMN_DEFINITIONS = 'model-schema-column-definitions:%s';

    // Store the relations associated with each model
    case MODEL_RELATIONS = 'model-relations:%s:%s';

    // Store the resource associated with each model
    case MODEL_RESOURCES = 'model-resources:%s';

    // Store the cached collection data for a repository (reference mode)
    case REPOSITORY_CACHE = 'repository-cache:%s';

    // Store the cache metadata for a repository
    case REPOSITORY_CACHE_META = 'repository-cache-meta:%s';

    // Store a per-query cached result for a repository (table, query hash)
    case REPOSITORY_QUERY_CACHE = 'repository-query:%s:%s';

    // Store the generational version that scopes a repository table's
    // per-query keys
    case REPOSITORY_CACHE_VERSION = 'repository-cache-version:%s';

    /**
     * Resolves the cache key with the necessary prefix and replaces any
     * placeholders.
     *
     * @param  array<int, string>  $replacements
     * @return string
     */
    public function resolveKey(array $replacements = []): string
    {
        $prefix = Config::get('api-toolkit.cache.prefix', 'api-toolkit');
        $prefix = is_string($prefix) ? $prefix : 'api-toolkit';

        $key = $prefix . ':' . $this->value;

        if (!empty($replacements)) {
            $key = vsprintf($key, $replacements);
        }

        return $key;
    }
}
