<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathTaggedController;

/**
 * Tests for the tag resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TagResolver::class)]
final class TagResolverTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver */
    private TagResolver $resolver;

    /**
     * Set up the resolver under test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new TagResolver;
    }

    /**
     * Test that a method-level tag wins over the class-level tag.
     *
     * @return void
     */
    public function testMethodTagOverridesClassTag(): void
    {
        self::assertSame('Reports', $this->resolver->resolve(
            PathTaggedController::class,
            'report',
            $this->route('reports'),
            null,
        ));
    }

    /**
     * Test that the class-level tag is used when the action declares none.
     *
     * @return void
     */
    public function testClassTagUsedWhenMethodHasNone(): void
    {
        self::assertSame('Widgets', $this->resolver->resolve(
            PathTaggedController::class,
            'index',
            $this->route('widgets'),
            null,
        ));
    }

    /**
     * Test that an explicit tag overrides the resource schema name.
     *
     * @return void
     */
    public function testExplicitTagOverridesSchemaName(): void
    {
        self::assertSame('Widgets', $this->resolver->resolve(
            PathTaggedController::class,
            'index',
            $this->route('widgets'),
            'User',
        ));
    }

    /**
     * Test that a class tag is still found when the action method is unknown.
     *
     * @return void
     */
    public function testUnknownMethodFallsBackToClassTag(): void
    {
        self::assertSame('Widgets', $this->resolver->resolve(
            PathTaggedController::class,
            'missing',
            $this->route('widgets'),
            null,
        ));
    }

    /**
     * Test that the resource schema name is used when no attribute applies.
     *
     * @return void
     */
    public function testSchemaNameUsedWhenNoAttribute(): void
    {
        self::assertSame('User', $this->resolver->resolve(
            PathPlainController::class,
            'index',
            $this->route('plain'),
            'User',
        ));
    }

    /**
     * Test that the controller basename is used, stripping a trailing
     * "Controller" suffix, when no attribute or schema name applies.
     *
     * @return void
     */
    public function testControllerBasenameUsedWithoutSuffix(): void
    {
        self::assertSame('PathInvokable', $this->resolver->resolve(
            PathInvokableController::class,
            '__invoke',
            $this->route('widgets'),
            null,
        ));
    }

    /**
     * Test that a closure route derives its tag from the first non-parameter
     * URI segment.
     *
     * @return void
     */
    public function testClosureDerivesTagFromUriSegment(): void
    {
        self::assertSame('health', $this->resolver->resolve(
            null,
            \Closure::class,
            $this->route('health/{check}'),
            null,
        ));
    }

    /**
     * Test that a closure route whose URI carries no literal segment falls back
     * to the shared default tag.
     *
     * @return void
     */
    public function testClosureWithoutLiteralSegmentFallsBackToDefault(): void
    {
        self::assertSame('default', $this->resolver->resolve(
            null,
            \Closure::class,
            $this->route('{any}'),
            null,
        ));
    }

    /**
     * Build a GET route for the given URI backed by a closure.
     *
     * @param  string  $uri
     * @return \Illuminate\Routing\Route
     */
    private function route(string $uri): Route
    {
        return new Route(['GET'], $uri, static fn (): null => null);
    }
}
