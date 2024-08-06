<?php

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use LogicException;

/**
 * Authorized API controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class AuthorizedController extends Controller
{
    use AuthorizesRequests;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->authorizeResource($this->getResourceModel(), $this->getRouteParameter());
    }

    /**
     * Get the resource model.
     *
     * @return string
     */
    public static function getResourceModel(): string
    {
        if (!defined(static::class . '::RESOURCE_MODEL')) {
            throw new LogicException('The RESOURCE_MODEL constant must be defined on the authorized controller');
        }

        return static::RESOURCE_MODEL;
    }

    /**
     * Get the route parameter.
     *
     * @return string|null
     */
    public static function getRouteParameter(): ?string
    {
        return defined(static::class . '::ROUTE_PARAMETER') ? constant(static::class . '::ROUTE_PARAMETER') : null;
    }
}
