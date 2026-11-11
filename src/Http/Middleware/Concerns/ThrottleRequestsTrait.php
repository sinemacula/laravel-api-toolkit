<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use SineMacula\ApiToolkit\Exceptions\RequestSignatureException;

/**
 * Throttle requests trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ThrottleRequestsTrait
{
    /**
     * Resolve request signature.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing,Squiz.Commenting.FunctionComment.TypeHintMissing
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\RequestSignatureException
     */
    protected function resolveRequestSignature(#[\SensitiveParameter] $request): string
    {
        // phpcs:enable
        // route() is documented as never null, so call the resolver directly
        $route = ($request->getRouteResolver())();

        if ($route === null) {
            throw new RequestSignatureException('Unable to generate the request signature. Route unavailable.');
        }

        $serverName = $request->server('SERVER_NAME');
        $user       = $request->user();
        $identifier = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        // Key anonymous callers by IP so they do not share one bucket
        return sha1(
            $request->method()
            . '|' . (is_string($serverName) ? $serverName : '')
            . '|' . $request->path()
            . '|' . (is_int($identifier) || is_string($identifier) || $identifier instanceof \Stringable ? $identifier : $request->ip()),
        );
    }
}
