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
 * The PSR-4 roots are supplied synthetically so detection is exercised without
 * relying on any on-disk module layout; the directories need not exist because
 * a synthetic, non-vendor path is honoured as a root.
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
     * Test that a class beneath a module namespace resolves to that module,
     * keyed by the namespace prefix and named by its last segment.
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
     * Test that a class whose first segment beneath the root is already
     * framework vocabulary belongs to no module.
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
     * Test that a renamed root is honoured, so the module is taken relative to
     * whatever non-vendor prefix the class sits under.
     *
     * @return void
     */
    public function testHonoursRenamedRoot(): void
    {
        $module = $this->resolver(['Verifast\\' => ['/verifast/src']])
            ->resolve('Verifast\Billing\Models\Invoice');

        self::assertInstanceOf(Module::class, $module);
        self::assertSame('Verifast\Billing', $module->key);
        self::assertSame('Billing', $module->name);
    }

    /**
     * Test that a class under a vendored root belongs to no module, because the
     * vendor tree is excluded from the matchable roots.
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
     * Test that a class is matched against its longest matching root. With the
     * more specific root selected, the module namespace begins at framework
     * vocabulary, so the class belongs to no module; a shorter-root match would
     * instead have produced a module, proving the longest root is chosen.
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
     * Test that a class carrying no framework-vocabulary segment beneath its
     * root belongs to no module, as there is no boundary to cut the module at.
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
