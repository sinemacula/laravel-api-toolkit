<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Output;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Exceptions\DocumentWriteException;
use SineMacula\ApiToolkit\OpenApi\Output\FilesystemDocumentWriter;

/**
 * Tests for the FilesystemDocumentWriter output adapter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilesystemDocumentWriter::class)]
final class FilesystemDocumentWriterTest extends TestCase
{
    /** @var string The temporary directory used for write assertions. */
    private string $directory;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/api-toolkit-writer-' . uniqid();
    }

    /**
     * Tear down each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * Test that the writer persists each document at its path, leaving no
     * staging file behind.
     *
     * @return void
     */
    public function testWritesEveryDocumentToItsPath(): void
    {
        $public   = $this->directory . '/openapi.public.json';
        $internal = $this->directory . '/openapi.internal.json';

        (new FilesystemDocumentWriter(new Filesystem))->write([$public => '{"openapi":"3.1.0"}', $internal => '{}']);

        self::assertSame('{"openapi":"3.1.0"}', file_get_contents($public));
        self::assertSame('{}', file_get_contents($internal));
        self::assertSame([$internal, $public], glob($this->directory . '/*'));
    }

    /**
     * Test that the writer replaces a document already at the path.
     *
     * @return void
     */
    public function testReplacesAnExistingDocument(): void
    {
        $path = $this->directory . '/openapi.json';

        (new Filesystem)->ensureDirectoryExists($this->directory);
        file_put_contents($path, 'stale');

        (new FilesystemDocumentWriter(new Filesystem))->write([$path => 'fresh']);

        self::assertSame('fresh', file_get_contents($path));
    }

    /**
     * Test that the writer creates a missing parent directory tree.
     *
     * @return void
     */
    public function testCreatesMissingParentDirectory(): void
    {
        $path = $this->directory . '/nested/deep/openapi.json';

        (new FilesystemDocumentWriter(new Filesystem))->write([$path => 'contents']);

        self::assertDirectoryExists($this->directory . '/nested/deep');
        self::assertSame('contents', file_get_contents($path));
    }

    /**
     * Test that a document that cannot be written leaves every other document
     * as it was and no staging file behind.
     *
     * @return void
     */
    public function testFailedDocumentLeavesEveryDocumentUnreplaced(): void
    {
        $public = $this->directory . '/openapi.public.json';
        $file   = $this->directory . '/file';

        (new Filesystem)->ensureDirectoryExists($this->directory);
        file_put_contents($public, 'previous');
        file_put_contents($file, '');

        $this->expectException(DocumentWriteException::class);
        $this->expectExceptionMessage(sprintf('Unable to create directory [%s] for the OpenAPI document.', $file));

        try {
            (new FilesystemDocumentWriter(new Filesystem))->write([$public => 'next', $file . '/openapi.internal.json' => 'next']);
        } finally {
            self::assertSame('previous', file_get_contents($public));
            self::assertSame([$file, $public], glob($this->directory . '/*'));
        }
    }

    /**
     * Test that the staging file is written beside the target and moved onto
     * it, the parent directory created recursively with the mode.
     *
     * @return void
     */
    public function testStagesBesideTheTargetThenMovesOntoIt(): void
    {
        $path      = $this->directory . '/nested/openapi.json';
        $directory = dirname($path);

        $files = $this->createMock(Filesystem::class);
        $files->method('isDirectory')->willReturn(false);
        $files->expects(self::once())
            ->method('makeDirectory')
            ->with($directory, 0o755, true, true)
            ->willReturn(true);
        $files->expects(self::once())->method('put')->with($path . '.tmp', 'contents')->willReturn(8);
        $files->expects(self::once())->method('move')->with($path . '.tmp', $path)->willReturn(true);
        $files->expects(self::once())->method('delete')->with([])->willReturn(true);

        (new FilesystemDocumentWriter($files))->write([$path => 'contents']);
    }

    /**
     * Test that a failed write surfaces as a DocumentWriteException naming the
     * document.
     *
     * @return void
     */
    public function testThrowsWhenTheWriteFails(): void
    {
        $path = $this->directory . '/openapi.json';

        $files = self::createStub(Filesystem::class);
        $files->method('isDirectory')->willReturn(true);
        $files->method('put')->willReturn(false);

        $this->expectException(DocumentWriteException::class);
        $this->expectExceptionMessage(sprintf('Unable to write the OpenAPI document to [%s].', $path));

        (new FilesystemDocumentWriter($files))->write([$path => 'contents']);
    }

    /**
     * Test that a failed move surfaces as a DocumentWriteException and removes
     * the staging files not yet moved.
     *
     * @return void
     */
    public function testThrowsAndRemovesUnmovedStagingWhenTheMoveFails(): void
    {
        $public   = $this->directory . '/openapi.public.json';
        $internal = $this->directory . '/openapi.internal.json';

        $files = $this->createMock(Filesystem::class);
        $files->method('isDirectory')->willReturn(true);
        $files->method('put')->willReturn(2);
        $files->method('move')->willReturnCallback(static fn (string $from, string $to): bool => $to === $public);
        $files->expects(self::once())->method('delete')->with([$internal . '.tmp'])->willReturn(true);

        $this->expectException(DocumentWriteException::class);
        $this->expectExceptionMessage(sprintf('Unable to write the OpenAPI document to [%s].', $internal));

        (new FilesystemDocumentWriter($files))->write([$public => '{}', $internal => '{}']);
    }
}
