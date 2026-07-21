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
     * Test that the writer persists the contents at the given path.
     *
     * @return void
     */
    public function testWritesContentsToPath(): void
    {
        $path = $this->directory . '/openapi.json';

        (new FilesystemDocumentWriter(new Filesystem))->write($path, '{"openapi":"3.1.0"}');

        self::assertFileExists($path);
        self::assertSame('{"openapi":"3.1.0"}', file_get_contents($path));
    }

    /**
     * Test that the writer creates a missing parent directory tree.
     *
     * @return void
     */
    public function testCreatesMissingParentDirectory(): void
    {
        $path = $this->directory . '/nested/deep/openapi.json';

        (new FilesystemDocumentWriter(new Filesystem))->write($path, 'contents');

        self::assertDirectoryExists($this->directory . '/nested/deep');
        self::assertSame('contents', file_get_contents($path));
    }

    /**
     * Test that the parent directory is created with the expected mode and the
     * recursive and force flags both enabled.
     *
     * @return void
     */
    public function testCreatesDirectoryWithExpectedModeAndFlags(): void
    {
        $path      = $this->directory . '/nested/openapi.json';
        $directory = dirname($path);

        $files = $this->createMock(Filesystem::class);
        $files->method('isDirectory')->willReturn(false);
        $files->expects(self::once())
            ->method('makeDirectory')
            ->with($directory, 0o755, true, true)
            ->willReturn(true);
        $files->method('put')->willReturn(8);

        (new FilesystemDocumentWriter($files))->write($path, 'contents');
    }

    /**
     * Test that a write failure surfaces as a DocumentWriteException.
     *
     * @return void
     */
    public function testThrowsWhenTheWriteFails(): void
    {
        $files = self::createStub(Filesystem::class);
        $files->method('isDirectory')->willReturn(true);
        $files->method('put')->willReturn(false);

        $this->expectException(DocumentWriteException::class);

        (new FilesystemDocumentWriter($files))->write($this->directory . '/openapi.json', 'contents');
    }

    /**
     * Test that an un-creatable directory surfaces as a DocumentWriteException.
     *
     * @return void
     */
    public function testThrowsWhenTheDirectoryCannotBeCreated(): void
    {
        $files = self::createStub(Filesystem::class);
        $files->method('isDirectory')->willReturn(false);
        $files->method('makeDirectory')->willReturn(false);

        $this->expectException(DocumentWriteException::class);

        (new FilesystemDocumentWriter($files))->write($this->directory . '/openapi.json', 'contents');
    }
}
