<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleDirectory;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;
use Tests\Concerns\WritesDocsDirectories;
use Tests\TestCase;

/**
 * Tests for the declared response example directory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleDirectory::class)]
final class ExampleDirectoryTest extends TestCase
{
    use WritesDocsDirectories;

    /**
     * Test that an audience's copy of a file replaces the shared one for that
     * audience only.
     *
     * @return void
     */
    public function testAudienceCopyReplacesTheSharedFile(): void
    {
        $this->useDocsDirectory([
            'examples/auth/signed-in.json'                    => '{"token": "shared"}',
            'audiences/internal/examples/auth/signed-in.json' => '{"token": "internal"}',
            'audiences/partner/examples/staff.json'           => '{"token": "partner"}',
        ]);

        $examples = new ExampleDirectory;

        self::assertEquals((object) ['token' => 'shared'], $examples->read('auth/signed-in.json', 'public'));
        self::assertEquals((object) ['token' => 'internal'], $examples->read('auth/signed-in.json', 'internal'));
        self::assertEquals((object) ['token' => 'partner'], $examples->read('staff.json', 'partner'));
    }

    /**
     * Test that a file is decoded as the JSON it holds, an empty object apart
     * from an empty list.
     *
     * @return void
     */
    public function testDecodesAnEmptyObjectApartFromAnEmptyList(): void
    {
        $this->useDocsDirectory(['examples/empty.json' => '{"meta": {}, "data": []}']);

        self::assertSame('{"meta":{},"data":[]}', json_encode((new ExampleDirectory)->read('empty.json', 'public'), JSON_THROW_ON_ERROR));
    }

    /**
     * Test that a file found in neither the audience's directory nor the shared
     * one fails, naming both.
     *
     * @return void
     */
    public function testMissingFileFails(): void
    {
        $dir = $this->useDocsDirectory();

        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage(sprintf(
            'The response example file [auth/signed-in.json] for the [internal] audience exists in neither %1$s/audiences/internal/examples nor %1$s/examples.',
            $dir,
        ));

        (new ExampleDirectory)->read('auth/signed-in.json', 'internal');
    }

    /**
     * Test that a file that is not valid JSON fails, naming the file and the
     * error.
     *
     * @return void
     */
    public function testInvalidJsonFails(): void
    {
        $dir = $this->useDocsDirectory(['examples/broken.json' => '{"token": ']);

        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage(sprintf('The response example file [%s/examples/broken.json] is not valid JSON: Syntax error.', $dir));

        (new ExampleDirectory)->read('broken.json', 'public');
    }

    /**
     * Test that a file linking outside its examples directory fails.
     *
     * @return void
     */
    public function testFileResolvingOutsideItsDirectoryFails(): void
    {
        $dir = $this->useDocsDirectory(['examples-archive/secret.json' => '{"key": "secret"}', 'examples/' => '']);

        symlink($dir . '/examples-archive/secret.json', $dir . '/examples/leak.json');

        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage(sprintf('The response example file [%1$s/examples/leak.json] resolves outside %1$s/examples.', $dir));

        (new ExampleDirectory)->read('leak.json', 'public');
    }

    /**
     * Test that the JSON files beneath a directory are listed by relative path
     * in path order, hidden entries and other files ignored.
     *
     * @return void
     */
    public function testListsTheJsonFilesBeneathADirectory(): void
    {
        $dir = $this->useDocsDirectory([
            'examples/users/b.json'                  => '{}',
            'examples/users/a.json'                  => '{}',
            'examples/c.json'                        => '{}',
            'examples/a.json'                        => '{}',
            'examples/b.json'                        => '{}',
            'examples/users/.draft.json'             => '{}',
            'examples/.archive/old.json'             => '{}',
            'examples/notes.md'                      => 'Notes.',
            'examples/folder.json/'                  => '',
            'audiences/internal/examples/staff.json' => '{}',
        ]);

        config()->set('api-toolkit.openapi.docs_path', $dir . '/');

        $examples = new ExampleDirectory;

        self::assertSame([
            'a.json'       => $dir . '/examples/a.json',
            'b.json'       => $dir . '/examples/b.json',
            'c.json'       => $dir . '/examples/c.json',
            'users/a.json' => $dir . '/examples/users/a.json',
            'users/b.json' => $dir . '/examples/users/b.json',
        ], $examples->files(null));
        self::assertSame(['staff.json' => $dir . '/audiences/internal/examples/staff.json'], $examples->files('internal'));
        self::assertSame([], $examples->files('public'));
    }
}
