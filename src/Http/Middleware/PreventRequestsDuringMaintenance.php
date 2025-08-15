<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Prevent requests during maintenance mode.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class PreventRequestsDuringMaintenance extends Middleware
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

        $this->except = Config::get('api-toolkit.maintenance_mode.except', []);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  Closure  $next
     * @return mixed
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return parent::handle($request, $next);
        } catch (HttpException $exception) {
            throw new MaintenanceModeException;
        }
    }
}
