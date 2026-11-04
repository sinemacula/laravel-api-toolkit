<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Registers the resource-derived morph aliases.
 *
 * Merges each mapped resource's type into the morph map as the alias of its
 * model. The application's own aliases always win: an alias it has registered,
 * or a model it has already mapped, is left alone. An alias claimed by more
 * than one model is registered for none of them. A morph map is never required,
 * so models without a resource keep morphing to their class name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MorphMapRegistrar
{
    /**
     * Register the resource-derived morph aliases.
     *
     * @return void
     *
     * @throws \LogicException
     */
    public function register(): void
    {
        /** @var array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>|null $map */
        $map = Config::get('api-toolkit.resources.resource_map');

        if (!Config::get('api-toolkit.resources.enable_dynamic_morph_mapping') || !is_array($map)) {
            return;
        }

        Relation::morphMap($this->withoutApplicationAliases($this->unambiguousAliases($map)));
    }

    /**
     * Derive the morph aliases claimed by exactly one model, reporting any type
     * shared by distinct resources.
     *
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $map
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     *
     * @throws \LogicException
     */
    private function unambiguousAliases(array $map): array
    {
        $claims = [];

        foreach ($map as $model => $resource) {
            if (method_exists($resource, 'getResourceType')) {
                $claims[$resource::getResourceType()][$model] = $resource;
            }
        }

        $aliases = [];

        foreach ($claims as $alias => $claimants) {

            $this->reportSharedType($alias, $claimants);

            if (count($claimants) === 1) {
                $aliases[$alias] = array_key_first($claimants);
            }
        }

        return $aliases;
    }

    /**
     * Report a resource type shared by distinct resources, fatally when schema
     * validation is enabled. A type claimed by a single resource, whether for
     * one model or several, is not reported.
     *
     * @param  string  $alias
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $claimants
     * @return void
     *
     * @throws \LogicException
     */
    private function reportSharedType(string $alias, array $claimants): void
    {
        $resources = array_unique($claimants);

        if (count($resources) === 1) {
            return;
        }

        $message = sprintf(
            'Dynamic morph mapping found multiple resources with the type [%s]: [%s]. '
            . 'No morph alias is registered for it; give each resource a distinct RESOURCE_TYPE '
            . 'or register the alias in the application morph map.',
            $alias,
            implode('], [', $resources),
        );

        if (Config::get('api-toolkit.resources.validate_schemas') === true) {
            throw new \LogicException($message);
        }

        Log::warning($message);
    }

    /**
     * Drop each derived alias the application has already registered, and each
     * one for a model the application has already mapped.
     *
     * @param  array<string, class-string<\Illuminate\Database\Eloquent\Model>>  $aliases
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function withoutApplicationAliases(array $aliases): array
    {
        $existing = Relation::morphMap();

        return array_filter(
            $aliases,
            static fn (string $model, string $alias): bool => !array_key_exists($alias, $existing) && !in_array($model, $existing, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
