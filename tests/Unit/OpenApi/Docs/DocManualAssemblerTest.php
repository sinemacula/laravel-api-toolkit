<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use Tests\TestCase;

/**
 * Tests for the committed Markdown manual assembler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocManualAssembler::class)]
final class DocManualAssemblerTest extends TestCase
{
    /** @var list<string> The temporary docs directories to clean up. */
    private array $dirs = [];

    /**
     * Tear down each test, removing any temporary docs directories.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }

        $this->dirs = [];

        parent::tearDown();
    }

    /**
     * Test that the section files are concatenated in filename (sorted) order,
     * separated by a blank line, regardless of the order they were written.
     *
     * @return void
     */
    public function testConcatenatesSectionsInFilenameOrder(): void
    {
        $dir = $this->makeDir([
            '30-b.md' => "# B\n\nSecond.",
            '10-a.md' => "# A\n\nFirst.",
        ]);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame("# A\n\nFirst.\n\n# B\n\nSecond.", (new DocManualAssembler)->assemble());
    }

    /**
     * Test that a missing docs directory yields an empty string rather than
     * throwing, keeping the manual opt-in.
     *
     * @return void
     */
    public function testMissingDirectoryYieldsEmptyString(): void
    {
        $this->config()->set('api-toolkit.openapi.docs_path', sys_get_temp_dir() . '/api-toolkit-missing-' . uniqid('', true));

        self::assertSame('', (new DocManualAssembler)->assemble());
    }

    /**
     * Test that an empty docs directory yields an empty string.
     *
     * @return void
     */
    public function testEmptyDirectoryYieldsEmptyString(): void
    {
        $dir = $this->makeDir([]);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame('', (new DocManualAssembler)->assemble());
    }

    /**
     * Test that non-Markdown files are ignored, so only the section files
     * contribute to the assembled manual.
     *
     * @return void
     */
    public function testIgnoresNonMarkdownFiles(): void
    {
        $dir = $this->makeDir([
            '10-a.md'   => 'Kept.',
            'notes.txt' => 'Dropped.',
        ]);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame('Kept.', (new DocManualAssembler)->assemble());
    }

    /**
     * Create a temporary docs directory seeded with the given files, registered
     * for teardown, and return its path.
     *
     * @param  array<string, string>  $files
     * @return string
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);

        mkdir($dir);

        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }

        $this->dirs[] = $dir;

        return $dir;
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
