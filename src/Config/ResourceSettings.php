<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;

/**
 * The typed resource settings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ResourceSettings
{
    /** @var string What the resource map's bindings must be */
    private const string BINDINGS = 'a map of Eloquent model classes to API resource classes';

    /**
     * Determine whether each resource's type is registered as its model's morph
     * alias.
     *
     * @return bool
     */
    public static function shouldMapMorphTypes(): bool
    {
        return ToolkitConfig::readBoolean('resources.enable_dynamic_morph_mapping');
    }

    /**
     * Return the paths scanned for resources, or null for the default roots.
     *
     * @return list<string>|null
     */
    public static function paths(): ?array
    {
        return ToolkitConfig::readValue('resources.paths') === null ? null : ToolkitConfig::readList('resources.paths');
    }

    /**
     * Return the configured model to resource bindings, checked for shape.
     *
     * @return array<string, string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function resourceMap(): array
    {
        $map = ToolkitConfig::readMap('resources.resource_map');

        foreach ($map as $model => $resource) {
            if (!is_string($model) || $model === '' || !is_string($resource) || $resource === '') {
                throw InvalidConfigurationException::mustBe(ToolkitConfig::key('resources.resource_map'), 'a map of model classes to resource classes', $resource);
            }
        }

        return $map;
    }

    /**
     * Return the configured bindings, each an Eloquent model bound to an API
     * resource.
     *
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function bindings(): array
    {
        $bindings = [];

        foreach (self::resourceMap() as $model => $resource) {

            if (!is_subclass_of($model, Model::class) || !is_subclass_of($resource, ApiResourceInterface::class)) {
                throw InvalidConfigurationException::mustBe(ToolkitConfig::key('resources.resource_map'), self::BINDINGS, $model . ' => ' . $resource);
            }

            $bindings[$model] = $resource;
        }

        return $bindings;
    }

    /**
     * Determine whether resource schemas are validated at boot.
     *
     * @return bool
     */
    public static function shouldValidateSchemas(): bool
    {
        return ToolkitConfig::readBoolean('resources.validate_schemas');
    }

    /**
     * Return the fields present in every resource response.
     *
     * @return list<string>
     */
    public static function fixedFields(): array
    {
        return ToolkitConfig::readList('resources.fixed_fields');
    }

    /**
     * Return the columns schema validation refuses as queryable.
     *
     * @return list<string>
     */
    public static function sensitiveColumns(): array
    {
        return ToolkitConfig::readList('resources.sensitive_columns');
    }

    /**
     * Determine whether reads select only the columns the resolved fields need.
     *
     * @return bool
     */
    public static function shouldNarrowColumns(): bool
    {
        return ToolkitConfig::readBoolean('resources.narrow_columns');
    }

    /**
     * Return the default per-parent bound of a to-many relation, 0 for none.
     *
     * @return int
     */
    public static function relationLimit(): int
    {
        return ToolkitConfig::readCap('resources.relation_limit');
    }
}
