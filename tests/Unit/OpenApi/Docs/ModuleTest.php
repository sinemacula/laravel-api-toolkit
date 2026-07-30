<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;

/**
 * Tests for the Module value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Module::class)]
final class ModuleTest extends TestCase
{
    /**
     * Test that the key and name are stored and accessible.
     *
     * @return void
     */
    public function testStoresKeyAndName(): void
    {
        $module = new Module('App\User', 'User');

        self::assertSame('App\User', $module->key);
        self::assertSame('User', $module->name);
    }
}
