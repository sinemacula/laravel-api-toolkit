<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\OperationDescriptions;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use Tests\Concerns\BuildsDocumentedRoutes;
use Tests\Concerns\WritesDocsDirectories;
use Tests\TestCase;

/**
 * Tests for the operation descriptions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OperationDescriptions::class)]
final class OperationDescriptionsTest extends TestCase
{
    use BuildsDocumentedRoutes, WritesDocsDirectories;

    /**
     * Test that an operation file fails the export while ids are off.
     *
     * @return void
     */
    public function testOperationFileFailsWhileIdsAreOff(): void
    {
        $dir = $this->useDocsDirectory(['audiences/internal/operations/users.index.md' => 'Lists the users.']);

        $this->expectException(OrphanedDocumentationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(sprintf(
            'The operation description [%s/audiences/internal/operations/users.index.md] cannot be matched while operation ids are off.'
                . ' Set api-toolkit.openapi.operation_ids to true.',
            $dir,
        ), '/') . '$/');

        (new OperationDescriptions)->for('public', new DocumentedRoutes(['public' => [self::documentedRoute('/users')]], false));
    }

    /**
     * Test that no operation file while ids are off resolves nothing.
     *
     * @return void
     */
    public function testNoOperationFileWhileIdsAreOffResolvesNothing(): void
    {
        $this->useDocsDirectory(['tags/Widget.md' => 'Widgets.']);

        self::assertSame([], (new OperationDescriptions)->for('public', new DocumentedRoutes(['public' => [self::documentedRoute('/users')]], false)));
    }

    /**
     * Test that the audience's descriptions resolve against every audience's
     * keys while ids are on.
     *
     * @return void
     */
    public function testDescriptionsResolveWhileIdsAreOn(): void
    {
        $this->useDocsDirectory([
            'operations/users.index.md'                    => 'Lists the users.',
            'operations/users.update.md'                   => 'Updates a user.',
            'audiences/internal/operations/staff.index.md' => 'Lists the staff.',
        ]);

        $routes = new DocumentedRoutes([
            'public' => [
                self::documentedRoute('/users', operationIds: ['get' => 'users.index']),
                self::documentedRoute('/users/{user}', ['put', 'patch'], ['put' => 'users.update', 'patch' => 'users.update.patch']),
            ],
            'internal' => [self::documentedRoute('/staff', operationIds: ['get' => 'staff.index'])],
        ], true);

        self::assertSame(['users.index' => 'Lists the users.', 'users.update' => 'Updates a user.'], (new OperationDescriptions)->for('public', $routes));
        self::assertSame(['staff.index' => 'Lists the staff.'], (new OperationDescriptions)->for('internal', $routes));
    }

    /**
     * Test that a route's shared description and a per-form description both
     * match its forms, even when another route shadows the shortest form.
     *
     * @return void
     */
    public function testSharedAndPerFormDescriptionsMatchTheForms(): void
    {
        $this->useDocsDirectory([
            'operations/reports.show.md'        => 'Shows a report.',
            'operations/reports.show.period.md' => 'Shows a report for a period.',
        ]);

        $forms = self::documentedForms('reports/{period?}', operationIds: [['get' => 'reports.show'], ['get' => 'reports.show.period']]);

        self::assertSame(
            ['reports.show' => 'Shows a report.', 'reports.show.period' => 'Shows a report for a period.'],
            (new OperationDescriptions)->for('public', new DocumentedRoutes(['public' => $forms], true)),
        );

        $shadowed = new DocumentedRoutes(['public' => [self::documentedRoute('/reports', operationIds: ['get' => 'reports.index']), ...$forms]], true);

        self::assertSame(
            ['reports.show.period' => 'Shows a report for a period.', 'reports.show' => 'Shows a report.'],
            (new OperationDescriptions)->for('public', $shadowed),
        );
    }
}
