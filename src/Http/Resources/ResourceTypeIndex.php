<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;

/**
 * Indexes the selectable resources by their resource type.
 *
 * The catalogue is the resource map's values and every resource discovered
 * under the configured paths, so a resource no model binds is selectable too.
 * It is rebuilt whenever either list changes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class ResourceTypeIndex
{
    /** @var array<int, mixed>|null The configured lists the index was built from */
    private static ?array $source = null;

    /** @var list<class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>> */
    private static array $classes = [];

    /** @var array<string, list<class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>>> */
    private static array $types = [];

    /**
     * Return the catalogued resources of the given type.
     *
     * @param  string  $type
     * @return list<class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>>
     */
    public static function classesFor(string $type): array
    {
        self::refresh();

        return self::$types[$type] ?? [];
    }

    /**
     * Return every catalogued resource.
     *
     * @return list<class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>>
     */
    public static function all(): array
    {
        self::refresh();

        return self::$classes;
    }

    /**
     * Rebuild the index when the lists it was built from have changed.
     *
     * @return void
     */
    private static function refresh(): void
    {
        $source = [
            Config::get('api-toolkit.resources.resource_map'),
            Config::get(ResourceDiscovery::RESOURCES_KEY),
        ];

        if ($source === self::$source) {
            return;
        }

        $candidates = array_merge(...array_map(static fn (mixed $list): array => is_array($list) ? $list : [], $source));

        self::$classes = array_values(array_unique(array_filter(
            $candidates,
            static fn (mixed $class): bool => is_string($class) && is_a($class, ApiResourceInterface::class, true),
        )));

        self::$types = [];

        foreach (self::$classes as $class) {
            self::$types[$class::getResourceType()][] = $class;
        }

        self::$source = $source;
    }
}
