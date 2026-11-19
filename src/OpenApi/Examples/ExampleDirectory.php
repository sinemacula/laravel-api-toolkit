<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;

/**
 * Locates and reads the declared response example files.
 *
 * `<docs_path>/examples/` holds the shared files, and an audience's
 * `audiences/<name>/examples/` copy of a file replaces it for that audience.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExampleDirectory
{
    /** The subdirectory holding the example files */
    private const string DIRECTORY = 'examples';

    /** The extension of every example file */
    private const string EXTENSION = '.json';

    /** The message for a file found in neither directory */
    private const string MISSING_MESSAGE = 'The response example file [%s] for the [%s] audience exists in neither %s nor %s.';

    /** The message for a file resolving outside its directory */
    private const string ESCAPE_MESSAGE = 'The response example file [%s] resolves outside %s.';

    /** The message for a file that is not valid JSON */
    private const string INVALID_MESSAGE = 'The response example file [%s] is not valid JSON: %s.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory  $directory
     */
    public function __construct(

        /** The docs directory the examples directories sit in. */
        private DocsDirectory $directory = new DocsDirectory,
    ) {}

    /**
     * Read the audience's copy of the file, else the shared one, decoded with
     * JSON objects as objects.
     *
     * @param  string  $file
     * @param  string  $audience
     * @return mixed
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     */
    public function read(string $file, string $audience): mixed
    {
        $own    = $this->root($audience);
        $shared = $this->root(null);
        $root   = is_file($own . '/' . $file) ? $own : $shared;
        $path   = $root . '/' . $file;

        if (!is_file($path)) {
            throw new ExampleDeclarationException(sprintf(self::MISSING_MESSAGE, $file, $audience, $own, $shared));
        }

        if (!str_starts_with((string) realpath($path), realpath($root) . DIRECTORY_SEPARATOR)) {
            throw new ExampleDeclarationException(sprintf(self::ESCAPE_MESSAGE, $path, $root));
        }

        try {
            return json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ExampleDeclarationException(sprintf(self::INVALID_MESSAGE, $path, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * Map each example file beneath the audience's directory, or the shared
     * one, by its path relative to it, in path order, ignoring hidden entries.
     *
     * @param  string|null  $audience
     * @return array<string, string>
     */
    public function files(?string $audience): array
    {
        $root = $this->root($audience);

        if (!is_dir($root)) {
            return [];
        }

        $visible = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !DocsDirectory::isHidden($entry->getFilename()),
        ));

        $examples = new \CallbackFilterIterator(
            $visible,
            static fn (\SplFileInfo $entry): bool => $entry->isFile() && str_ends_with($entry->getFilename(), self::EXTENSION),
        );

        $files = [];

        /** @var \SplFileInfo $entry */
        foreach ($examples as $entry) {
            $files[substr($entry->getPathname(), strlen($root) + 1)] = $entry->getPathname();
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Resolve the examples directory of the audience, or the shared one.
     *
     * @param  string|null  $audience
     * @return string
     */
    private function root(?string $audience): string
    {
        $root = $audience === null ? $this->directory->root() : $this->directory->audienceRoot($audience);

        return rtrim($root, '/') . '/' . self::DIRECTORY;
    }
}
