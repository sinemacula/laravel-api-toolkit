<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Prevent requests during maintenance mode.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);

        /** @var array<int, string> $except */
        $except = Config::get('api-toolkit.maintenance_mode.except', []);

        $this->except = $except;
    }

    /**
     * Handle an incoming request.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing,Squiz.Commenting.FunctionComment.TypeHintMissing
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     * @return mixed
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\MaintenanceModeException
     *
     * @phpstan-ignore method.childParameterType
     */
    #[\Override]
    public function handle(#[\SensitiveParameter] $request, \Closure $next): mixed
    {
        // phpcs:enable
        try {
            return parent::handle($request, $next);
        } catch (HttpException $exception) {
            throw new MaintenanceModeException;
        }
    }
}
