<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use PHPUnit\Framework\Assert;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;

/**
 * Registers the toolkit exception handler for full-stack feature tests.
 *
 * Mirrors the bootstrap/app.php withExceptions() wiring used by consuming
 * applications, so dispatched requests render failures through the toolkit JSON
 * error envelope rather than the framework default.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RegistersApiExceptionHandler
{
    /**
     * Register the toolkit exception handler against the application's real
     * exception handler.
     *
     * @return void
     */
    protected function registerApiExceptionHandler(): void
    {
        $handler = app(ExceptionHandlerContract::class);

        if (!$handler instanceof Handler) {
            Assert::fail('The application exception handler must extend the foundation handler.');
        }

        ApiExceptionHandler::handles(new Exceptions($handler));
    }
}
