<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseExampleResolver;

/**
 * Fails the export on a response example file no exported operation names.
 *
 * A shared file must be named in some audience and an audience's own file in
 * that audience. Every exported audience's files are audited, whichever one is
 * exported.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExampleFileAudit
{
    /** The message for a shared file no audience names */
    private const string SHARED_ORPHAN_MESSAGE = 'The example file [%s] is named by no #[ResponseExample] in any audience.';

    /** The message for an audience's file the audience does not name */
    private const string AUDIENCE_ORPHAN_MESSAGE = 'The example file [%s] is named by no #[ResponseExample] in the [%s] audience.';

    /** The message for a file under a directory of no exported audience */
    private const string UNKNOWN_AUDIENCE_MESSAGE = 'The example file [%s] belongs to the [%s] audience, which is not configured.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\ExampleDirectory  $examples
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseExampleResolver  $declarations
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory  $directory
     */
    public function __construct(

        /** The directory of the example files. */
        private ExampleDirectory $examples = new ExampleDirectory,

        /** The resolver of the examples each action declares. */
        private ResponseExampleResolver $declarations = new ResponseExampleResolver,

        /** The docs directory holding the audience directories. */
        private DocsDirectory $directory = new DocsDirectory,
    ) {}

    /**
     * Assert that every example file is named by an operation of the routes'
     * audiences: shared files first, then each audience's, then those of
     * audiences outside the export.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes  $routes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    public function assert(DocumentedRoutes $routes): void
    {
        $named = [];

        foreach ($routes->audiences() as $audience) {
            $named[$audience] = $this->named($routes, $audience);
        }

        $this->assertNamed($this->examples->files(null), array_merge(...array_values($named)), null);

        foreach ($routes->audiences() as $audience) {
            $this->assertNamed($this->examples->files($audience), $named[$audience], $audience);
        }

        foreach (array_diff($this->directory->audienceDirectories(), $routes->audiences()) as $audience) {

            $files = $this->examples->files($audience);

            if ($files !== []) {
                throw new OrphanedDocumentationException(sprintf(self::UNKNOWN_AUDIENCE_MESSAGE, reset($files), $audience));
            }
        }
    }

    /**
     * List the example files the audience's exported operations name.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes  $routes
     * @param  string  $audience
     * @return list<string>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     */
    private function named(DocumentedRoutes $routes, string $audience): array
    {
        $files = [];

        foreach ($routes->operations($audience) as $verbs) {
            foreach ($verbs as $route) {
                foreach ($this->declarations->forAction($route->controllerClass, $route->action) as $example) {
                    $files[] = $example->file;
                }
            }
        }

        return $files;
    }

    /**
     * Assert that every file is among those named.
     *
     * @param  array<string, string>  $files
     * @param  list<string>  $named
     * @param  string|null  $audience
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    private function assertNamed(array $files, array $named, ?string $audience): void
    {
        $orphans = array_diff_key($files, array_flip($named));

        if ($orphans === []) {
            return;
        }

        $message = $audience === null
            ? sprintf(self::SHARED_ORPHAN_MESSAGE, reset($orphans))
            : sprintf(self::AUDIENCE_ORPHAN_MESSAGE, reset($orphans), $audience);

        throw new OrphanedDocumentationException($message);
    }
}
