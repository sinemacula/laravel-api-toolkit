<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;
use SineMacula\ApiToolkit\OpenApi\Docs\NamespaceModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Metadata\Psr4RootMap;
use Tests\TestCase;

/**
 * Tests for the namespace-key module resolver.
 *
 * PSR-4 roots are synthetic; a non-vendor root need not exist on disk.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NamespaceModuleResolver::class)]
final class NamespaceModuleResolverTest extends TestCase
{
    /**
     * Test that a class beneath a module namespace resolves to that module.
     *
     * @return void
     */
    public function testResolvesModuleFromNamespace(): void
    {
        $module = $this->resolver(['App\\' => ['/app/src']])
            ->resolve('App\User\Http\Controllers\ProfileController');

        self::assertInstanceOf(Module::class, $module);
        self::assertSame('App\User', $module->key);
        self::assertSame('User', $module->name);
    }

    /**
     * Test that a class whose first segment is framework vocabulary has none.
     *
     * @return void
     */
    public function testFrameworkFirstSegmentYieldsNoModule(): void
    {
        $module = $this->resolver(['App\\' => ['/app/src']])
            ->resolve('App\Exceptions\PaymentFailed');

        self::assertNull($module);
    }

    /**
     * Test that a renamed non-vendor root is honoured.
     *
     * @return void
     */
    public function testHonoursRenamedRoot(): void
    {
        $module = $this->resolver(['Acme\\' => ['/acme/src']])
            ->resolve('Acme\Billing\Models\Invoice');

        self::assertInstanceOf(Module::class, $module);
        self::assertSame('Acme\Billing', $module->key);
        self::assertSame('Billing', $module->name);
    }

    /**
     * Test that a class under a vendored root belongs to no module.
     *
     * @return void
     */
    public function testVendorRootYieldsNoModule(): void
    {
        $module = $this->resolver(['SineMacula\ApiToolkit\\' => ['/base/vendor/sinemacula/api-toolkit/src']])
            ->resolve('SineMacula\ApiToolkit\Exceptions\NotFoundException');

        self::assertNull($module);
    }

    /**
     * Test that a class matching no configured root belongs to no module.
     *
     * @return void
     */
    public function testUnmatchedClassYieldsNoModule(): void
    {
        $module = $this->resolver(['App\\' => ['/app/src']])
            ->resolve('Other\Billing\Models\Invoice');

        self::assertNull($module);
    }

    /**
     * Test that a class is matched against its longest matching root.
     *
     * A shorter-root match would produce a module; the longest one yields none.
     *
     * @return void
     */
    public function testMatchesLongestRoot(): void
    {
        $module = $this->resolver([
            'Shop\\'          => ['/shop/src'],
            'Shop\Internal\\' => ['/shop/internal/src'],
        ])->resolve('Shop\Internal\Enums\Status');

        self::assertNull($module);
    }

    /**
     * Test that a class without a framework-vocabulary segment has no module.
     *
     * @return void
     */
    public function testClassWithoutFrameworkSegmentYieldsNoModule(): void
    {
        $module = $this->resolver(['App\\' => ['/app/src']])
            ->resolve('App\User\Profile');

        self::assertNull($module);
    }

    /**
     * Build a resolver over the given synthetic PSR-4 prefix map.
     *
     * @param  array<string, array<int, string>>  $prefixes
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\NamespaceModuleResolver
     */
    private function resolver(array $prefixes): NamespaceModuleResolver
    {
        return new NamespaceModuleResolver(new Psr4RootMap($prefixes));
    }
}
