<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

use SineMacula\ApiToolkit\Config\LifecycleSettings;

/**
 * Define the keys used for the cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CacheKeys: string
{
    // Store the columns associated with each model, per schema identity
    case MODEL_SCHEMA_COLUMNS = 'model-schema-columns:%s:%s';

    // Store the per-column type/nullability definitions, per schema identity
    case MODEL_SCHEMA_COLUMN_DEFINITIONS = 'model-schema-column-definitions:%s:%s';

    // Store the indexes declared on each model's table, per schema identity
    case MODEL_SCHEMA_INDEXES = 'model-schema-indexes:%s:%s';

    // Store the relations associated with each model
    case MODEL_RELATIONS = 'model-relations:%s:%s';

    // Store the resource associated with each model
    case MODEL_RESOURCES = 'model-resources:%s';

    // Store the index proof behind a search surface, per schema identity
    case SEARCH_INDEX_PROOF = 'search-index-proof:%s:%s';

    // Store the generation every metadata key is namespaced by
    case METADATA_GENERATION = 'metadata-generation';

    /**
     * Resolves the cache key with the necessary prefix and replaces any
     * placeholders.
     *
     * @param  array<int, string>  $replacements
     * @return string
     */
    public function resolveKey(array $replacements = []): string
    {
        $key = LifecycleSettings::cachePrefix() . ':' . $this->value;

        if (!empty($replacements)) {
            $key = vsprintf($key, $replacements);
        }

        return $key;
    }
}
