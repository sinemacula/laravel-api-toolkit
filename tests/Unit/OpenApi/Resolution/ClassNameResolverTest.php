<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use Tests\TestCase;

/**
 * Tests for the class name resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ClassNameResolver::class)]
final class ClassNameResolverTest extends TestCase
{
    /** @var string The namespace every generated fixture class is declared under */
    private const string SCRATCH = 'Tests\Scratch\ClassNameResolver';

    /** @var string The fixture controller naming its exceptions in every form a class name takes */
    private const string IMPORTED = 'Tests\Fixtures\OpenApi\ImportedThrowsController';

    /** @var array<int, string> The generated fixture files to remove */
    private array $files = [];

    /**
     * Remove the generated fixture files.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach (array_filter($this->files, is_file(...)) as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Test that a name written fully qualified is returned without its leading
     * backslash, whatever the file imports.
     *
     * @return void
     */
    public function testFullyQualifiedNameDropsLeadingBackslash(): void
    {
        $method = $this->imported('show');

        self::assertSame('ForbiddenException', (new ClassNameResolver)->resolve('\ForbiddenException', $method));
    }

    /**
     * Test that a short name matching an import, a plain, aliased, or grouped
     * one, compared case-insensitively, resolves to the imported name.
     *
     * @return void
     */
    public function testImportedNamesResolveToTheirImports(): void
    {
        $method   = $this->imported('show');
        $resolver = new ClassNameResolver;

        self::assertSame('SineMacula\ApiToolkit\Exceptions\ForbiddenException', $resolver->resolve('ForbiddenException', $method));
        self::assertSame('SineMacula\ApiToolkit\Exceptions\ForbiddenException', $resolver->resolve('forbiddenexception', $method));
        self::assertSame('SineMacula\ApiToolkit\Exceptions\TokenMismatchException', $resolver->resolve('CsrfMismatchException', $method));
        self::assertSame('SineMacula\ApiToolkit\Exceptions\ConflictException', $resolver->resolve('ConflictException', $method));
        self::assertSame('SineMacula\ApiToolkit\Exceptions\GoneException', $resolver->resolve('ExpiredException', $method));
    }

    /**
     * Test that a qualified name whose first segment matches an import resolves
     * under the imported name.
     *
     * @return void
     */
    public function testQualifiedNameResolvesUnderImportedNamespace(): void
    {
        $method = $this->imported('show');

        self::assertSame(
            'SineMacula\ApiToolkit\Exceptions\Auth\UnauthenticatedException',
            (new ClassNameResolver)->resolve('Exceptions\Auth\UnauthenticatedException', $method),
        );
    }

    /**
     * Test that a name matching no import resolves relative to the enclosing
     * namespace, including a name an import only aliases and a name written
     * with the namespace prefix.
     *
     * @return void
     */
    public function testUnimportedNameResolvesRelativeToNamespace(): void
    {
        $method   = $this->imported('show');
        $resolver = new ClassNameResolver;

        self::assertSame('Tests\Fixtures\OpenApi\QuotaExceededException', $resolver->resolve('QuotaExceededException', $method));
        self::assertSame('Tests\Fixtures\OpenApi\GoneException', $resolver->resolve('GoneException', $method));
        self::assertSame('Tests\Fixtures\OpenApi\Throws\Missing', $resolver->resolve('Throws\Missing', $method));
        self::assertSame('Tests\Fixtures\OpenApi\Thing', $resolver->resolve('namespace\Thing', $method));
        self::assertSame('Tests\Fixtures\OpenApi\namespaced', $resolver->resolve('namespaced', $method));
    }

    /**
     * Test that inherited and trait methods resolve against the file declaring
     * them rather than the class they are reflected from.
     *
     * @return void
     */
    public function testInheritedAndTraitMethodsResolveAgainstTheirOwnFile(): void
    {
        $resolver = new ClassNameResolver;

        self::assertSame(
            'SineMacula\ApiToolkit\Exceptions\LockedException',
            $resolver->resolve('LockedException', $this->imported('lock')),
        );
        self::assertSame(
            'SineMacula\ApiToolkit\Exceptions\PayloadTooLargeException',
            $resolver->resolve('PayloadTooLargeException', $this->imported('upload')),
        );
        self::assertSame(
            'Tests\Fixtures\OpenApi\ImportedThrows\Concerns\ForbiddenException',
            $resolver->resolve('ForbiddenException', $this->imported('upload')),
        );
    }

    /**
     * Test that each braced namespace block, named or global, resolves against
     * its own imports and namespace only.
     *
     * @return void
     */
    public function testBracedNamespacesResolveAgainstTheirOwnBlock(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\First {
                use Vendor\First\Conflict as Clash;

                final class BracedFirst { public function action(): void { if (true) {} } }
            }

            namespace ClassNameResolverScratch {
                use Vendor\Second\Gone as Clash;

                final class BracedSecond { public function action(): void {} }
            }

            namespace {
                use Vendor\Global\Missing as Clash;

                final class ClassNameResolverBracedGlobal { public function action(): void {} }
            }
            PHP);

        $resolver = new ClassNameResolver;

        self::assertSame('Vendor\First\Conflict', $resolver->resolve('Clash', $this->action(self::SCRATCH . '\First\BracedFirst')));
        self::assertSame('Vendor\Second\Gone', $resolver->resolve('Clash', $this->action('ClassNameResolverScratch\BracedSecond')));
        self::assertSame('ClassNameResolverScratch\Other', $resolver->resolve('Other', $this->action('ClassNameResolverScratch\BracedSecond')));
        self::assertSame('Vendor\Global\Missing', $resolver->resolve('Clash', $this->action('ClassNameResolverBracedGlobal')));
        self::assertSame('Other', $resolver->resolve('Other', $this->action('ClassNameResolverBracedGlobal')));
    }

    /**
     * Test that a later unbraced namespace statement starts a fresh block, even
     * one sharing a line with the method, and a file without one resolves
     * against the global namespace, past any leading braced statement block.
     *
     * @return void
     */
    public function testNamespaceStatementsStartFreshBlocks(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Earlier;

            use Vendor\Earlier\Thing;

            final class EarlierBlock { public function action(): void {} }

            namespace Tests\Scratch\ClassNameResolver\Later;

            final class LaterBlock { public function action(): void {} }
            PHP);

        $this->load(<<<'PHP'
            <?php

            use Vendor\Unnamespaced\Thing;

            final class ClassNameResolverUnnamespaced { public function action(): void {} }
            PHP);

        $this->load(<<<'PHP'
            <?php {{}} use Vendor\Leading\Thing; final class ClassNameResolverLeadingBlock { public function action(): void {} }
            PHP);

        $this->load(<<<'PHP'
            <?php namespace Tests\Scratch\ClassNameResolver\Inline; final class InlineBlock { public function action(): void {} }
            PHP);

        $resolver = new ClassNameResolver;

        self::assertSame('Vendor\Earlier\Thing', $resolver->resolve('Thing', $this->action(self::SCRATCH . '\Earlier\EarlierBlock')));
        self::assertSame(self::SCRATCH . '\Later\Thing', $resolver->resolve('Thing', $this->action(self::SCRATCH . '\Later\LaterBlock')));
        self::assertSame('Vendor\Unnamespaced\Thing', $resolver->resolve('Thing', $this->action('ClassNameResolverUnnamespaced')));
        self::assertSame('Other', $resolver->resolve('Other', $this->action('ClassNameResolverUnnamespaced')));
        self::assertSame('Vendor\Leading\Thing', $resolver->resolve('Thing', $this->action('ClassNameResolverLeadingBlock')));
        self::assertSame(self::SCRATCH . '\Inline\Thing', $resolver->resolve('Thing', $this->action(self::SCRATCH . '\Inline\InlineBlock')));
        self::assertSame('Thing', $resolver->resolve('namespace\Thing', $this->action('ClassNameResolverUnnamespaced')));
    }

    /**
     * Test that every clause of a multi-clause use statement is imported, and a
     * fully qualified import drops its leading backslash.
     *
     * @return void
     */
    public function testEveryClauseOfAUseStatementIsImported(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Clauses;

            use \Vendor\Leading\First, Vendor\Plain\Second as Renamed;
            use Vendor\Group\{Third, Nested\Fourth as Deep};
            use \Vendor\Rooted\{Sixth};

            final class ClauseController { public function action(): void {} }
            PHP);

        $method   = $this->action(self::SCRATCH . '\Clauses\ClauseController');
        $resolver = new ClassNameResolver;

        self::assertSame('Vendor\Leading\First', $resolver->resolve('First', $method));
        self::assertSame('Vendor\Plain\Second', $resolver->resolve('Renamed', $method));
        self::assertSame(self::SCRATCH . '\Clauses\Second', $resolver->resolve('Second', $method));
        self::assertSame('Vendor\Group\Third', $resolver->resolve('Third', $method));
        self::assertSame('Vendor\Group\Nested\Fourth', $resolver->resolve('Deep', $method));
        self::assertSame('Vendor\Rooted\Sixth', $resolver->resolve('Sixth', $method));
    }

    /**
     * Test that function and constant imports, whether whole statements or
     * items of a mixed group, are not imports of a class.
     *
     * @return void
     */
    public function testFunctionAndConstantImportsAreIgnored(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Functions;

            use function Vendor\Functions\helper;
            use const Vendor\Constants\LIMIT;
            use function Vendor\Grouped\{first, second};
            use Vendor\Mixed\{function third, const FOURTH, Fifth};

            final class FunctionController { public function action(): void {} }
            PHP);

        $method   = $this->action(self::SCRATCH . '\Functions\FunctionController');
        $resolver = new ClassNameResolver;

        self::assertSame(self::SCRATCH . '\Functions\helper', $resolver->resolve('helper', $method));
        self::assertSame(self::SCRATCH . '\Functions\LIMIT', $resolver->resolve('LIMIT', $method));
        self::assertSame(self::SCRATCH . '\Functions\second', $resolver->resolve('second', $method));
        self::assertSame(self::SCRATCH . '\Functions\third', $resolver->resolve('third', $method));
        self::assertSame(self::SCRATCH . '\Functions\FOURTH', $resolver->resolve('FOURTH', $method));
        self::assertSame('Vendor\Mixed\Fifth', $resolver->resolve('Fifth', $method));
    }

    /**
     * Test that trait uses inside a class body, even after an interpolated
     * string, and closure uses are not imports.
     *
     * @return void
     */
    public function testTraitAndClosureUsesAreNotImports(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Concerns;

            trait Greets {}
            PHP);

        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Traits;

            $suffix  = '';
            $closure = static function () use ($suffix): string { return $suffix; };

            final class TraitController
            {
                public string $name = '';

                public function greet(): string { return "Hello {$this->name}"; }

                use \Tests\Scratch\ClassNameResolver\Concerns\Greets;

                public function action(): void {}
            }
            PHP);

        $method   = $this->action(self::SCRATCH . '\Traits\TraitController');
        $resolver = new ClassNameResolver;

        self::assertSame(self::SCRATCH . '\Traits\Greets', $resolver->resolve('Greets', $method));
        self::assertSame(self::SCRATCH . '\Traits\string', $resolver->resolve('string', $method));
    }

    /**
     * Test that keywords used as method names and literal braces inside
     * interpolated strings neither open a namespace nor shift the brace depth.
     *
     * @return void
     */
    public function testKeywordNamesAndStringBracesKeepTheScope(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\KeywordConcerns;

            trait Greets {}
            PHP);

        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Keywords;

            $open = '';
            $text = "$open{";

            use Vendor\Keywords\Thing;

            final class KeywordController
            {
                public function namespace(): void {}

                public function close(string $value): string { return "$value}"; }

                use \Tests\Scratch\ClassNameResolver\KeywordConcerns\Greets;

                public function action(): void {}
            }
            PHP);

        $method   = $this->action(self::SCRATCH . '\Keywords\KeywordController');
        $resolver = new ClassNameResolver;

        self::assertSame('Vendor\Keywords\Thing', $resolver->resolve('Thing', $method));
        self::assertSame(self::SCRATCH . '\Keywords\Greets', $resolver->resolve('Greets', $method));
    }

    /**
     * Test that an import declared after the method does not apply to it.
     *
     * @return void
     */
    public function testLaterImportsDoNotApplyToEarlierMethods(): void
    {
        $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Ordered;

            use Vendor\Ordered\Earlier;

            final class OrderedController { public function action(): void {} }

            use Vendor\Ordered\Later;
            PHP);

        $method   = $this->action(self::SCRATCH . '\Ordered\OrderedController');
        $resolver = new ClassNameResolver;

        self::assertSame('Vendor\Ordered\Earlier', $resolver->resolve('Earlier', $method));
        self::assertSame(self::SCRATCH . '\Ordered\Later', $resolver->resolve('Later', $method));
    }

    /**
     * Test that a method whose file cannot be read resolves against its class's
     * namespace with no imports.
     *
     * @return void
     */
    public function testUnreadableFileResolvesAgainstClassNamespace(): void
    {
        $file = $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Unreadable;

            use Vendor\Unreadable\Thing;

            final class UnreadableController { public function action(): void {} }
            PHP);

        unlink($file);

        self::assertSame(
            self::SCRATCH . '\Unreadable\Thing',
            (new ClassNameResolver)->resolve('Thing', $this->action(self::SCRATCH . '\Unreadable\UnreadableController')),
        );
    }

    /**
     * Test that each file is read once, so a name keeps resolving against the
     * imports first read after the file is gone.
     *
     * @return void
     */
    public function testEachFileIsReadOnce(): void
    {
        $file = $this->load(<<<'PHP'
            <?php

            namespace Tests\Scratch\ClassNameResolver\Cached;

            use Vendor\Cached\Thing;

            final class CachedController { public function action(): void {} }
            PHP);

        $method   = $this->action(self::SCRATCH . '\Cached\CachedController');
        $resolver = new ClassNameResolver;

        self::assertSame('Vendor\Cached\Thing', $resolver->resolve('Thing', $method));

        unlink($file);

        self::assertSame('Vendor\Cached\Thing', $resolver->resolve('Thing', $method));
    }

    /**
     * Write the source to a generated fixture file and load it.
     *
     * @param  string  $source
     * @return string
     */
    private function load(string $source): string
    {
        $file = sys_get_temp_dir() . '/class-name-resolver-' . uniqid((string) getmypid(), true) . '.php';

        file_put_contents($file, $source);

        $this->files[] = $file;

        require $file;

        return $file;
    }

    /**
     * Reflect the given method of the imported-names fixture controller.
     *
     * @param  string  $method
     * @return \ReflectionMethod
     */
    private function imported(string $method): \ReflectionMethod
    {
        require_once dirname(__DIR__, 3) . '/Fixtures/OpenApi/ImportedThrows/ImportedThrowsController.stub';

        return new \ReflectionMethod(self::IMPORTED, $method);
    }

    /**
     * Reflect the action method of the given generated class.
     *
     * @param  string  $class
     * @return \ReflectionMethod
     */
    private function action(string $class): \ReflectionMethod
    {
        return new \ReflectionMethod($class, 'action');
    }
}
