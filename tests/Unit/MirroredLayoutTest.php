<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests that every unit test sits at the path mirroring its source file.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class MirroredLayoutTest extends TestCase
{
    /** @var array<int, string> Unit tests that cover no single source file. */
    private const array UNMIRRORED = [
        'Exceptions/ConcreteExceptionsTest.php',
        'MirroredLayoutTest.php',
    ];

    /**
     * Test that each unit test has a source file at its mirrored path.
     *
     * @return void
     */
    public function testEveryUnitTestMirrorsASourceFile(): void
    {
        $source = dirname(__DIR__, 2) . '/src/';
        $files  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS),
        );

        $unmirrored = [];

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen(__DIR__) + 1);

            if (!str_ends_with($path, 'Test.php') || in_array($path, self::UNMIRRORED, true)) {
                continue;
            }

            if (is_file($source . substr($path, 0, -strlen('Test.php')) . '.php')) {
                continue;
            }

            $unmirrored[] = $path;
        }

        sort($unmirrored);

        self::assertSame([], $unmirrored);
    }
}
