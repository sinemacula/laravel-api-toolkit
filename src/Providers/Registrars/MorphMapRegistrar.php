<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Registers the resource-derived morph aliases.
 *
 * The application's own aliases always win, and an alias claimed by more than
 * one model is registered for none of them.
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

        Relation::morphMap($this->derivedAliases($map, Relation::morphMap()));
    }

    /**
     * Derive the morph aliases the application has left unclaimed, withholding
     * any alias claimed by more than one model.
     *
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $map
     * @param  array<string, class-string>  $existing
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     *
     * @throws \LogicException
     */
    private function derivedAliases(array $map, array $existing): array
    {
        $aliases = [];

        foreach ($this->claims($map) as $alias => $claimants) {

            if (array_key_exists($alias, $existing)) {
                continue;
            }

            $this->reportSharedType($alias, $claimants);

            $model = array_key_first($claimants);

            if (count($claimants) !== 1 || in_array($model, $existing, true)) {
                continue;
            }

            $aliases[$alias] = $model;
        }

        return $aliases;
    }

    /**
     * Group the mapped models by the type of their resource, skipping any
     * resource without one.
     *
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $map
     * @return array<string, non-empty-array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>>
     */
    private function claims(array $map): array
    {
        $claims = [];

        foreach ($map as $model => $resource) {

            if (!method_exists($resource, 'getResourceType')) {
                continue;
            }

            $claims[$resource::getResourceType()][$model] = $resource;
        }

        return $claims;
    }

    /**
     * Report a resource type shared by distinct resources, fatally when schema
     * validation is enabled.
     *
     * @param  int|string  $alias
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $claimants
     * @return void
     *
     * @throws \LogicException
     */
    private function reportSharedType(int|string $alias, array $claimants): void
    {
        $resources = array_unique($claimants);

        if (count($resources) === 1) {
            return;
        }

        $message = sprintf(
            'Dynamic morph mapping found multiple resources with the type [%s]: [%s]. '
            . 'No morph alias is registered for it; give each resource a distinct RESOURCE_TYPE, '
            . 'or register the alias in the application morph map from the register method of a service provider.',
            $alias,
            implode('], [', $resources),
        );

        if (Config::get('api-toolkit.resources.validate_schemas') === true) {
            throw new \LogicException($message);
        }

        Log::warning($message);
    }
}
