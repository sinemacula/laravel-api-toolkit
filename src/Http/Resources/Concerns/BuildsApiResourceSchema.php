<?php

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Builds API resource schema-derived relation maps and count definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
trait BuildsApiResourceSchema
{
    /**
     * Collect count definitions (presentation key => normalized def).
     *
     * @return array<string, array{
     *   relation: string,
     *   constraint?: \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void,
     *   default?: bool,
     *   guards?: array<int, \Closure(\SineMacula\ApiToolkit\Http\Resources\ApiResource, ?\Illuminate\Http\Request): bool>
     * }>
     */
    private static function countDefinitions(): array
    {
        $definitions = [];

        foreach (static::getCompiledSchema() as $schema_key => $definition) {
            $normalized = self::normalizeCountDefinition($schema_key, $definition);

            if ($normalized === null) {
                continue;
            }

            $definitions[$normalized['key']] = $normalized['definition'];
        }

        return $definitions;
    }

    /**
     * Normalize a raw schema count entry.
     *
     * @param  string  $schema_key
     * @param  array<string, mixed>  $definition
     * @return array{key: string, definition: array{
     *   relation: string,
     *   constraint?: \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void,
     *   default?: bool,
     *   guards?: array<int, \Closure(\SineMacula\ApiToolkit\Http\Resources\ApiResource, ?\Illuminate\Http\Request): bool>
     * }}|null
     */
    private static function normalizeCountDefinition(string $schema_key, array $definition): ?array
    {
        if (($definition['metric'] ?? null) !== 'count') {
            return null;
        }

        $present_key = self::resolveCountPresentKey($schema_key, $definition);
        $relation    = is_string($definition['relation'] ?? null) && $definition['relation'] !== ''
            ? $definition['relation']
            : $present_key;

        $entry = [
            'relation' => $relation,
            'default'  => (bool) ($definition['default'] ?? false),
            'guards'   => self::resolveCountGuards($definition),
        ];

        $constraint = self::extractConstraint($definition);

        if ($constraint !== null) {
            $entry['constraint'] = $constraint;
        }

        return [
            'key'        => $present_key,
            'definition' => $entry,
        ];
    }

    /**
     * Resolve count key for output payload.
     *
     * @param  string  $schema_key
     * @param  array<string, mixed>  $definition
     * @return string
     */
    private static function resolveCountPresentKey(string $schema_key, array $definition): string
    {
        if (is_string($definition['key'] ?? null)) {
            return $definition['key'];
        }

        if (str_starts_with($schema_key, '__count__:')) {
            return substr($schema_key, 10);
        }

        return $schema_key;
    }

    /**
     * Resolve and normalize guards for a count definition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, \Closure(\SineMacula\ApiToolkit\Http\Resources\ApiResource, ?\Illuminate\Http\Request): bool>
     */
    private static function resolveCountGuards(array $definition): array
    {
        $raw_guards = is_array($definition['guards'] ?? null) ? $definition['guards'] : [];
        $guards     = [];

        foreach ($raw_guards as $guard) {
            if (!is_callable($guard)) {
                continue;
            }

            $guards[] = static fn (ApiResource $resource, ?Request $request): bool => (bool) $guard($resource, $request);
        }

        return $guards;
    }

    /**
     * Decide if a count should be included based on request or default flag.
     *
     * @param  string  $present_key
     * @param  array<int, string>|null  $requested
     * @param  array{default?: bool}  $definition
     * @return bool
     */
    private static function shouldIncludeCount(string $present_key, ?array $requested, array $definition): bool
    {
        if (is_array($requested) && $requested !== []) {
            return in_array($present_key, $requested, true);
        }

        return $definition['default'] ?? false;
    }

    /**
     * Orchestrate relation traversal for a resource class (constrained map mode).
     *
     * @param  class-string  $resource
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @return void
     */
    private static function walkRelationsWith(string $resource, array $fields, string $prefix, array &$plain, array &$scoped, array &$visited): void
    {
        $schema = $resource::getCompiledSchema();

        foreach ($fields as $field) {
            $definition = self::findDefinition($schema, $field);

            if ($definition === null || ($definition['metric'] ?? null) !== null) {
                continue;
            }

            self::processRelationDefinition($resource, $definition, $prefix, $plain, $scoped, $visited);
        }
    }

    /**
     * Process one relation-capable schema definition.
     *
     * @param  class-string  $resource
     * @param  array<string, mixed>  $definition
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @return void
     */
    private static function processRelationDefinition(string $resource, array $definition, string $prefix, array &$plain, array &$scoped, array &$visited): void
    {
        $constraint = self::extractConstraint($definition);

        foreach (self::extractExtraPaths($definition) as $extra) {
            $plain[] = self::makePrefixedPath($prefix, $extra);
        }

        foreach (self::extractRelations($definition) as $relation) {
            $full_path = self::makePrefixedPath($prefix, $relation);

            if (self::wasVisited($visited, $resource, $full_path)) {
                continue;
            }

            self::markVisited($visited, $resource, $full_path);
            self::registerRelationPath($constraint, $full_path, $plain, $scoped);

            if (!self::shouldRecurseIntoChild($definition)) {
                continue;
            }

            $child_resource = $definition['resource'];
            $child_fields   = self::resolveChildFields($definition, $child_resource);

            if ($child_fields !== []) {
                self::walkRelationsWith($child_resource, $child_fields, $full_path, $plain, $scoped, $visited);
            }
        }
    }

    /**
     * Register a plain or constrained eager-load path.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void|null  $constraint
     * @param  string  $full_path
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @return void
     */
    private static function registerRelationPath(?\Closure $constraint, string $full_path, array &$plain, array &$scoped): void
    {
        if (!$constraint instanceof \Closure) {
            $plain[] = $full_path;
            return;
        }

        $scoped[$full_path] = static function ($query) use ($constraint): void {
            if ($query instanceof MorphTo) {
                $constraint($query);
                return;
            }

            $builder = $query instanceof EloquentRelation ? $query->getQuery() : $query;

            if ($builder instanceof Builder) {
                $constraint($builder);
            }
        };
    }

    /**
     * Find a field definition in a schema.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  string  $field
     * @return array<string, mixed>|null
     */
    private static function findDefinition(array $schema, string $field): ?array
    {
        $definition = $schema[$field] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Extract declared relation names from a definition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private static function extractRelations(array $definition): array
    {
        $relations = isset($definition['relation']) ? (array) $definition['relation'] : [];

        return array_values(
            array_filter($relations, static fn ($relation): bool => is_string($relation) && $relation !== ''),
        );
    }

    /**
     * Extract extra eager-load paths from a definition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private static function extractExtraPaths(array $definition): array
    {
        $extras = is_array($definition['extras'] ?? null) ? $definition['extras'] : [];

        return array_values(
            array_filter($extras, static fn ($path): bool => is_string($path) && $path !== ''),
        );
    }

    /**
     * Extract a scoped eager-load constraint from a relation definition, if present.
     *
     * @param  array<string, mixed>  $definition
     * @return \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void|null
     */
    private static function extractConstraint(array $definition): ?\Closure
    {
        $constraint = $definition['constraint'] ?? null;

        return $constraint instanceof \Closure ? $constraint : null;
    }

    /**
     * Build a dot-prefixed path.
     *
     * @param  string  $prefix
     * @param  string  $suffix
     * @return string
     */
    private static function makePrefixedPath(string $prefix, string $suffix): string
    {
        return $prefix === '' ? $suffix : $prefix . '.' . $suffix;
    }

    /**
     * Check if a (resource_class, path) pair has been visited.
     *
     * @param  array<string, bool>  $visited
     * @param  string  $resource
     * @param  string  $path
     * @return bool
     */
    private static function wasVisited(array $visited, string $resource, string $path): bool
    {
        return isset($visited[$resource . '|' . $path]);
    }

    /**
     * Mark a (resource_class, path) pair as visited.
     *
     * @param  array<string, bool>  $visited
     * @param  string  $resource
     * @param  string  $path
     * @return void
     */
    private static function markVisited(array &$visited, string $resource, string $path): void
    {
        $visited[$resource . '|' . $path] = true;
    }

    /**
     * Decide whether to recurse into a child resource.
     *
     * @param  array<string, mixed>  $definition
     * @return bool
     */
    private static function shouldRecurseIntoChild(array $definition): bool
    {
        return isset($definition['resource'])
            && is_string($definition['resource'])
            && $definition['resource'] !== ''
            && is_subclass_of($definition['resource'], ApiResource::class);
    }

    /**
     * Resolve child fields to traverse.
     *
     * @param  array<string, mixed>  $definition
     * @param  class-string  $resource
     * @return array<int, string>
     */
    private static function resolveChildFields(array $definition, string $resource): array
    {
        $overrides = $definition['fields'] ?? null;

        if (is_array($overrides) && $overrides !== []) {
            return array_values(
                array_filter(
                    $overrides,
                    static fn ($field): bool => is_string($field) && $field !== '',
                ),
            );
        }

        if (is_subclass_of($resource, ApiResource::class)) {
            $requested = ApiQuery::getFields($resource::getResourceType());

            if (is_array($requested) && $requested !== []) {
                return array_values(
                    array_filter(
                        $requested,
                        static fn ($field): bool => is_string($field) && $field !== '',
                    ),
                );
            }
        }

        $defaults = $resource::getDefaultFields();

        return $defaults !== [] ? $defaults : $resource::getAllFields();
    }
}
