<?php

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Authorized API controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class AuthorizedController extends Controller
{
    use AuthorizesRequests;

    /**
     * Constructor.
     */
    public function __construct()
    {
        // @phpstan-ignore staticMethod.dynamicCall (calling static method on instance is valid PHP)
        $this->authorizeResource($this->getResourceModel(), $this->getRouteParameter(), ['except' => $this->getGuardExclusions()]);
    }

    /**
     * Get the resource model.
     *
     * @return string
     */
    public static function getResourceModel(): string
    {
        if (!defined(static::class . '::RESOURCE_MODEL')) {
            throw new \LogicException('The RESOURCE_MODEL constant must be defined on the authorized controller');
        }

        /** @phpstan-ignore classConstant.notFound (subclasses are required to define RESOURCE_MODEL) */
        return static::RESOURCE_MODEL;
    }

    /**
     * Get the route parameter.
     *
     * @return string|null
     */
    public static function getRouteParameter(): ?string
    {
        return defined(static::class . '::ROUTE_PARAMETER') ? strtolower(constant(static::class . '::ROUTE_PARAMETER')) : null;
    }

    /**
     * Get the guard exclusions.
     *
     * @return array<int, string>|null
     */
    private function getGuardExclusions(): ?array
    {
        $value = defined(static::class . '::GUARD_EXCLUSIONS') ? constant(static::class . '::GUARD_EXCLUSIONS') : null;

        return is_array($value) ? $value : null;
    }
}
