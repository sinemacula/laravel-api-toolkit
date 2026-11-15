<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory;
use Tests\Concerns\WritesDocsDirectories;
use Tests\TestCase;

/**
 * Tests for the docs directory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocsDirectory::class)]
final class DocsDirectoryTest extends TestCase
{
    use WritesDocsDirectories;

    /**
     * Test that the root is the configured docs path, else the resources
     * default.
     *
     * @return void
     */
    public function testRootIsTheConfiguredPathElseTheResourcesDefault(): void
    {
        $this->config()->set('api-toolkit.openapi.docs_path', 'docs/api');

        self::assertSame('docs/api', (new DocsDirectory)->root());

        foreach (['', null, 42] as $unset) {

            $this->config()->set('api-toolkit.openapi.docs_path', $unset);

            self::assertSame(resource_path('api-docs'), (new DocsDirectory)->root());
        }
    }

    /**
     * Test that an audience's root sits under the audiences directory.
     *
     * @return void
     */
    public function testAudienceRootSitsUnderTheAudiencesDirectory(): void
    {
        $this->config()->set('api-toolkit.openapi.docs_path', 'docs/api/');

        self::assertSame('docs/api/audiences/internal', (new DocsDirectory)->audienceRoot('internal'));
    }

    /**
     * Test that only the Markdown files directly within are listed, in filename
     * order.
     *
     * @return void
     */
    public function testListsOnlyTheTopLevelMarkdownFilesInOrder(): void
    {
        $dir = $this->writeDocsDirectory([
            'b.md'        => 'B.',
            'a.md'        => 'A.',
            'c.txt'       => 'C.',
            'nested/d.md' => 'D.',
        ]);

        $directory = new DocsDirectory;

        self::assertSame([$dir . '/a.md', $dir . '/b.md'], $directory->markdownIn($dir));
        self::assertSame([$dir . '/a.md', $dir . '/b.md'], $directory->markdownIn($dir . '/'));
        self::assertSame([], $directory->markdownIn($dir . '/absent'));
    }

    /**
     * Test that the audience directories are listed by name, ignoring files.
     *
     * @return void
     */
    public function testListsTheAudienceDirectoriesByName(): void
    {
        $directory = new DocsDirectory;

        $this->config()->set('api-toolkit.openapi.docs_path', $this->writeDocsDirectory());

        self::assertSame([], $directory->audienceDirectories());

        $this->config()->set('api-toolkit.openapi.docs_path', $this->writeDocsDirectory([
            'audiences/public/'   => '',
            'audiences/internal/' => '',
            'audiences/stray.md'  => 'Stray.',
        ]));

        self::assertSame(['internal', 'public'], $directory->audienceDirectories());
    }

    /**
     * Test that a file is read trimmed, and as empty when it holds nothing.
     *
     * @return void
     */
    public function testReadsAFileTrimmed(): void
    {
        $dir = $this->writeDocsDirectory([
            'a.md'       => "\n  # A\n\nFirst.\n\n",
            'blank.md'   => " \n\t",
            'folder.md/' => '',
        ]);

        $directory = new DocsDirectory;

        self::assertSame("# A\n\nFirst.", $directory->read($dir . '/a.md'));
        self::assertSame('', $directory->read($dir . '/blank.md'));
        self::assertSame('', $directory->read($dir . '/folder.md'));
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }
}
