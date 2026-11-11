<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;

/**
 * Registers the toolkit exception handler as bootstrap/app.php does in a
 * consuming application.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RegistersApiExceptionHandler
{
    /**
     * Register the toolkit exception handler against the application's
     * exception handler.
     *
     * @return void
     */
    protected function registerApiExceptionHandler(): void
    {
        ApiExceptionHandler::handles($this->exceptionConfiguration());
    }

    /**
     * Wrap the application's real exception handler in the configuration that
     * bootstrap/app.php hands to withExceptions().
     *
     * @return \Illuminate\Foundation\Configuration\Exceptions
     */
    protected function exceptionConfiguration(): Exceptions
    {
        $handler = app(ExceptionHandlerContract::class);

        self::assertInstanceOf(Handler::class, $handler);

        return new Exceptions($handler);
    }
}
