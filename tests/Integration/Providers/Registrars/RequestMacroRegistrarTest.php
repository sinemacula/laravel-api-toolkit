<?php

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Support\Facades\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Providers\Registrars\RequestMacroRegistrar;
use Tests\TestCase;

/**
 * Integration tests for the RequestMacroRegistrar.
 *
 * The deprecated macro behaviour is pinned by the ApiServiceProvider
 * integration suite; this test proves the registrar binds its surface when
 * invoked directly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestMacroRegistrar::class)]
class RequestMacroRegistrarTest extends TestCase
{
    /**
     * Test that all deprecated request macros are registered when the
     * registrar is invoked.
     *
     * @return void
     */
    public function testRegisterBindsAllDeprecatedRequestMacros(): void
    {
        (new RequestMacroRegistrar)->register();

        static::assertTrue(Request::hasMacro('includeTrashed'));
        static::assertTrue(Request::hasMacro('onlyTrashed'));
        static::assertTrue(Request::hasMacro('expectsExport'));
        static::assertTrue(Request::hasMacro('expectsCsv'));
        static::assertTrue(Request::hasMacro('expectsXml'));
        static::assertTrue(Request::hasMacro('expectsPdf'));
        static::assertTrue(Request::hasMacro('expectsStream'));
    }
}
