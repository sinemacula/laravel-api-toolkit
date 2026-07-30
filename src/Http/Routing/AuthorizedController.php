<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\Attributes\SkipAuthorization;

/**
 * Authorized API controller.
 *
 * Resource authorization is declared with the #[AuthorizesResource] attribute
 * and applied through the framework's controller middleware contract:
 * middleware() emits one gate check per REST action, mapping each action to its
 * ability and binding it to either the model class or the route parameter,
 * while honouring both the attribute's except list and any action marked
 * #[SkipAuthorization].
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class AuthorizedController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    /** The map of resource actions to their gate abilities. */
    private const array RESOURCE_ABILITY_MAP = [
        'index'   => 'viewAny',
        'show'    => 'view',
        'create'  => 'create',
        'store'   => 'create',
        'edit'    => 'update',
        'update'  => 'update',
        'destroy' => 'delete',
    ];

    /** The resource actions authorized against the model class, not an instance. */
    private const array RESOURCE_METHODS_WITHOUT_MODELS = ['index', 'create', 'store'];

    /**
     * Get the resource model.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \LogicException
     */
    public static function getResourceModel(): string
    {
        $attribute = self::resolveAttribute();

        if ($attribute === null) {
            throw new \LogicException('The #[AuthorizesResource] attribute must be declared on the authorized controller');
        }

        $model = $attribute->model;

        if (!is_subclass_of($model, Model::class)) {
            throw new \LogicException('The #[AuthorizesResource] model must be an Eloquent model');
        }

        return $model;
    }

    /**
     * Get the middleware that should be assigned to the controller.
     *
     * A missing attribute fails closed: an authorized controller with no
     * #[AuthorizesResource] declaration is a misconfiguration, so it errors
     * rather than silently registering an ungated resource.
     *
     * @return array<int, \Illuminate\Routing\Controllers\Middleware>
     *
     * @throws \LogicException
     */
    #[\Override]
    public static function middleware(): array
    {
        $attribute = self::resolveAttribute();

        if ($attribute === null) {
            throw new \LogicException('The #[AuthorizesResource] attribute must be declared on the authorized controller');
        }

        return self::buildMiddleware(
            $attribute->model,
            self::resolveParameter($attribute),
            self::resolveExclusions($attribute),
        );
    }

    /**
     * Resolve the resource authorization attribute declared on the controller.
     *
     * @return \SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource|null
     */
    private static function resolveAttribute(): ?AuthorizesResource
    {
        $attributes = (new \ReflectionClass(static::class))->getAttributes(AuthorizesResource::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Resolve the route parameter the model instance binds to.
     *
     * @param  \SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource  $attribute
     * @return string
     */
    private static function resolveParameter(AuthorizesResource $attribute): string
    {
        return $attribute->parameter !== null && $attribute->parameter !== ''
            ? $attribute->parameter
            : Str::snake(class_basename($attribute->model));
    }

    /**
     * Resolve the actions left outside the authorization guard, combining the
     * attribute's except list with every action marked #[SkipAuthorization].
     *
     * @param  \SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource  $attribute
     * @return array<int, string>
     */
    private static function resolveExclusions(AuthorizesResource $attribute): array
    {
        return array_merge($attribute->except, self::skippedMethods());
    }

    /**
     * List the public action methods marked with #[SkipAuthorization].
     *
     * @return array<int, string>
     */
    private static function skippedMethods(): array
    {
        $methods = [];

        foreach ((new \ReflectionClass(static::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(SkipAuthorization::class) === []) {
                continue;
            }

            $methods[] = $method->getName();
        }

        return $methods;
    }

    /**
     * Build the gate middleware, grouping actions that share the same check
     * into a single entry keyed by ability and authorization target.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  string  $parameter
     * @param  array<int, string>  $exclusions
     * @return array<int, \Illuminate\Routing\Controllers\Middleware>
     */
    private static function buildMiddleware(string $model, string $parameter, array $exclusions): array
    {
        $groups = [];

        foreach (self::RESOURCE_ABILITY_MAP as $method => $ability) {

            if (in_array($method, $exclusions, true)) {
                continue;
            }

            $target = in_array($method, self::RESOURCE_METHODS_WITHOUT_MODELS, true) ? $model : $parameter;

            $groups["can:{$ability},{$target}"][] = $method;
        }

        return array_map(
            static fn (array $methods, string $definition): Middleware => new Middleware($definition, only: $methods),
            $groups,
            array_keys($groups),
        );
    }
}
