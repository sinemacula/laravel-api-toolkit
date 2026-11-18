<?php

declare(strict_types = 1);

namespace Tests\Integration\Config;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Exceptions\DeprecatedException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

/**
 * Tests that a deprecation raised inside the booted application fails the test
 * rather than going to the deprecations log.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class DeprecationsFailTheSuiteTest extends TestCase
{
    /**
     * Test that a deprecation raised while serving a request is thrown.
     *
     * @return void
     */
    public function testDeprecationRaisedWhileServingARequestIsThrown(): void
    {
        Route::get('/deprecated', static function (): string {
            trigger_error('Probe deprecation', E_USER_DEPRECATED);

            return 'served';
        });

        $this->withoutExceptionHandling();

        $this->expectException(DeprecatedException::class);
        $this->expectExceptionMessage('Probe deprecation');

        $this->get('/deprecated');
    }
}
