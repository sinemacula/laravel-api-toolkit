<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleFileAudit;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathFormResolver;
use Tests\Concerns\WritesDocsDirectories;
use Tests\Fixtures\OpenApi\PathResponseExampleController;
use Tests\TestCase;

/**
 * Tests for the audit of the declared response example files.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleFileAudit::class)]
final class ExampleFileAuditTest extends TestCase
{
    use WritesDocsDirectories;

    /**
     * Test that shared files named in any audience, and audience files named in
     * their own, pass.
     *
     * @return void
     */
    public function testNamedFilesPass(): void
    {
        $this->expectNotToPerformAssertions();

        $this->useDocsDirectory([
            'examples/auth/signed-in.json'                   => '{}',
            'examples/auth/remembered.json'                  => '{}',
            'examples/auth/pending.json'                     => '{}',
            'audiences/internal/examples/auth/pending.json'  => '{}',
            'audiences/public/examples/auth/remembered.json' => '{}',
            'audiences/retired/examples/'                    => '',
            'audiences/retired/notes.md'                     => 'Notes.',
        ]);

        (new ExampleFileAudit)->assert(new DocumentedRoutes([
            'public'   => [$this->route('/sign-in', 'signIn')],
            'internal' => [$this->route('/challenge', 'challenge')],
        ], false));
    }

    /**
     * Test that a shared file no audience names fails.
     *
     * @return void
     */
    public function testSharedFileNamedNowhereFails(): void
    {
        $dir = $this->useDocsDirectory(['examples/auth/signed-in.json' => '{}', 'examples/auth/stale.json' => '{}']);

        $this->expectOrphan(sprintf('The example file [%s/examples/auth/stale.json] is named by no #[ResponseExample] in any audience.', $dir));

        (new ExampleFileAudit)->assert(new DocumentedRoutes(['public' => [], 'internal' => [$this->route('/sign-in', 'signIn')]], false));
    }

    /**
     * Test that an audience's file named only by another audience fails.
     *
     * @return void
     */
    public function testAudienceFileNamedOnlyElsewhereFails(): void
    {
        $dir = $this->useDocsDirectory(['audiences/public/examples/auth/pending.json' => '{}']);

        $this->expectOrphan(sprintf('The example file [%s/audiences/public/examples/auth/pending.json] is named by no #[ResponseExample] in the [public] audience.', $dir));

        (new ExampleFileAudit)->assert(new DocumentedRoutes([
            'internal' => [$this->route('/challenge', 'challenge')],
            'public'   => [$this->route('/sign-in', 'signIn')],
        ], false));
    }

    /**
     * Test that a file named only by an operation a later route overwrites is
     * an orphan.
     *
     * @return void
     */
    public function testFileNamedOnlyByAnOverwrittenOperationFails(): void
    {
        $dir = $this->useDocsDirectory(['examples/auth/pending.json' => '{}', 'examples/auth/signed-in.json' => '{}']);

        $this->expectOrphan(sprintf('The example file [%s/examples/auth/pending.json] is named by no #[ResponseExample] in any audience.', $dir));

        (new ExampleFileAudit)->assert(new DocumentedRoutes(['public' => [$this->route('/auth', 'challenge'), $this->route('/auth', 'queue')]], false));
    }

    /**
     * Test that a file of an audience outside the export fails.
     *
     * @return void
     */
    public function testFileOfAnUnconfiguredAudienceFails(): void
    {
        $dir = $this->useDocsDirectory(['audiences/empty/examples/' => '', 'audiences/staff/examples/auth/signed-in.json' => '{}']);

        $this->expectOrphan(sprintf('The example file [%s/audiences/staff/examples/auth/signed-in.json] belongs to the [staff] audience, which is not configured.', $dir));

        (new ExampleFileAudit)->assert(new DocumentedRoutes(['public' => [$this->route('/sign-in', 'signIn')]], false));
    }

    /**
     * Test that shared files are audited before an audience's own.
     *
     * @return void
     */
    public function testSharedFilesAreAuditedFirst(): void
    {
        $dir = $this->useDocsDirectory(['audiences/public/examples/a.json' => '{}', 'examples/z.json' => '{}']);

        $this->expectOrphan(sprintf('The example file [%s/examples/z.json] is named by no #[ResponseExample] in any audience.', $dir));

        (new ExampleFileAudit)->assert(new DocumentedRoutes(['public' => []], false));
    }

    /**
     * Test that an invalid declaration in an audience other than the one
     * exported still fails.
     *
     * @return void
     */
    public function testInvalidDeclarationInAnyAudienceFails(): void
    {
        $this->useDocsDirectory();

        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage(sprintf('The response example [signed-in] is declared more than once on %s::duplicated.', PathResponseExampleController::class));

        (new ExampleFileAudit)->assert(new DocumentedRoutes(['public' => [], 'internal' => [$this->route('/sign-in', 'duplicated')]], false));
    }

    /**
     * Expect the audit to fail with the orphaned file message.
     *
     * @param  string  $message
     * @return void
     */
    private function expectOrphan(string $message): void
    {
        $this->expectException(OrphanedDocumentationException::class);
        $this->expectExceptionMessage($message);
    }

    /**
     * Build a documented GET route dispatching to an action of the example
     * controller.
     *
     * @param  string  $path
     * @param  string  $action
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private function route(string $path, string $action): DocumentedRoute
    {
        $route = new Route(['GET'], ltrim($path, '/'), [PathResponseExampleController::class, $action]);
        $form  = (new RoutePathFormResolver)->resolve($route)[0];

        return new DocumentedRoute($route, PathResponseExampleController::class, $action, $form, ['get'], null, 'Auth', [], null, false, $form->path, null);
    }
}
