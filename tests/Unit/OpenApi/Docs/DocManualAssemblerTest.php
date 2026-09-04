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
            $this->removeDirectory($dir);
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
     * Test that an audience reads its own section files alongside the shared
     * ones, ordered by filename across both, so a generated per-audience
     * section still lands where its numeric prefix puts it.
     *
     * @return void
     */
    public function testAudienceSectionsJoinTheSharedOnesInFilenameOrder(): void
    {
        $dir = $this->makeDir(['10-a.md' => 'Shared A.', '30-c.md' => 'Shared C.']);

        $this->writeAudienceFiles($dir, 'internal', ['20-b.md' => 'Internal B.']);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame(
            "Shared A.\n\nInternal B.\n\nShared C.",
            (new DocManualAssembler)->assemble('internal'),
        );
    }

    /**
     * Test that one audience never reads another's section files, which is the
     * whole reason a section can be written per audience.
     *
     * @return void
     */
    public function testOneAudienceNeverReadsAnothersSections(): void
    {
        $dir = $this->makeDir(['10-a.md' => 'Shared.']);

        $this->writeAudienceFiles($dir, 'internal', ['60-surface.md' => 'Internal only.']);
        $this->writeAudienceFiles($dir, 'public', ['60-surface.md' => 'Public only.']);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        $assembler = new DocManualAssembler;

        self::assertSame("Shared.\n\nPublic only.", $assembler->assemble('public'));
        self::assertSame("Shared.\n\nInternal only.", $assembler->assemble('internal'));
    }

    /**
     * Test that an audience's file replaces a shared file of the same name, so
     * a section moved to per-audience generation is never carried twice nor
     * shadowed by the shared copy left behind.
     *
     * @return void
     */
    public function testAudienceFileReplacesTheSharedFileOfTheSameName(): void
    {
        $dir = $this->makeDir(['10-a.md' => 'Shared.', '60-surface.md' => 'Stale shared surface.']);

        $this->writeAudienceFiles($dir, 'public', ['60-surface.md' => 'Public surface.']);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame("Shared.\n\nPublic surface.", (new DocManualAssembler)->assemble('public'));
    }

    /**
     * Test that naming no audience reads the shared files alone, so a caller
     * with no audience in hand is never handed one audience's sections.
     *
     * @return void
     */
    public function testNamingNoAudienceReadsTheSharedFilesAlone(): void
    {
        $dir = $this->makeDir(['10-a.md' => 'Shared.']);

        $this->writeAudienceFiles($dir, 'public', ['60-surface.md' => 'Public surface.']);

        // A file dropped in the audiences directory itself belongs to no
        // audience, so nothing reads it - naming no audience least of all.
        $this->writeFiles($dir . '/' . DocManualAssembler::AUDIENCE_DIRECTORY, ['90-stray.md' => 'Stray.']);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame('Shared.', (new DocManualAssembler)->assemble());
    }

    /**
     * Test that an audience with no section directory of its own reads the
     * shared files rather than nothing.
     *
     * @return void
     */
    public function testAudienceWithoutItsOwnDirectoryReadsTheSharedFiles(): void
    {
        $dir = $this->makeDir(['10-a.md' => 'Shared.']);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame('Shared.', (new DocManualAssembler)->assemble('partner'));
    }

    /**
     * Test that a section file holding nothing but whitespace is dropped rather
     * than joined as a blank section, so the sections either side of it stay
     * exactly one blank line apart.
     *
     * @return void
     */
    public function testDropsASectionFileThatReadsEmpty(): void
    {
        $dir = $this->makeDir([
            '10-a.md'     => 'First.',
            '20-blank.md' => "  \n\n\t",
            '30-c.md'     => 'Third.',
        ]);

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame("First.\n\nThird.", (new DocManualAssembler)->assemble());
    }

    /**
     * Test that an entry the read cannot open - a directory named as a section
     * file - is dropped rather than aborting the assembly, so the readable
     * sections are still returned.
     *
     * @return void
     */
    public function testDropsAnEntryThatCannotBeRead(): void
    {
        $dir = $this->makeDir(['10-a.md' => 'First.', '30-c.md' => 'Third.']);

        mkdir($dir . '/20-unreadable.md');

        $this->config()->set('api-toolkit.openapi.docs_path', $dir);

        self::assertSame("First.\n\nThird.", (new DocManualAssembler)->assemble());
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

        $this->dirs[] = $dir;

        $this->writeFiles($dir, $files);

        return $dir;
    }

    /**
     * Write the given files into an audience's own section directory beneath
     * the docs directory.
     *
     * @param  string  $dir
     * @param  string  $audience
     * @param  array<string, string>  $files
     * @return void
     */
    private function writeAudienceFiles(string $dir, string $audience, array $files): void
    {
        $path = $dir . '/' . DocManualAssembler::AUDIENCE_DIRECTORY . '/' . $audience;

        mkdir($path, 0o777, true);

        $this->writeFiles($path, $files);
    }

    /**
     * Write the given files into a directory.
     *
     * @param  string  $directory
     * @param  array<string, string>  $files
     * @return void
     */
    private function writeFiles(string $directory, array $files): void
    {
        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }
    }

    /**
     * Remove a directory and everything beneath it.
     *
     * @param  string  $directory
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
                continue;
            }

            unlink($entry);
        }

        @rmdir($directory);
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
