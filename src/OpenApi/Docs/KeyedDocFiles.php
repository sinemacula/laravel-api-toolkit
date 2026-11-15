<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;

/**
 * Resolves the Markdown description files filed under a key.
 *
 * `<docs_path>/<kind>/<key>.md` is shared, and an audience's
 * `audiences/<name>/<kind>/<key>.md` replaces it for that audience. A key is
 * matched byte for byte against filenames after its reserved bytes are
 * percent-encoded. A file matching no key fails the export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class KeyedDocFiles
{
    /** The message for a shared file matching no key in any audience */
    private const string SHARED_ORPHAN_MESSAGE = 'The %s description [%s] matches no %s in any audience.';

    /** The message for an audience's file matching none of its keys */
    private const string AUDIENCE_ORPHAN_MESSAGE = 'The %s description [%s] matches no %s in the [%s] audience.';

    /** The message for a file under a directory of no exported audience */
    private const string UNKNOWN_AUDIENCE_MESSAGE = 'The %s description [%s] belongs to the [%s] audience, which is not configured.';

    /** The bytes a key encodes, besides a leading dot */
    private const string RESERVED_BYTES = '/[%\/\\\:*?"<>|\x00-\x1F\x7F]/';

    /** The extension of every description file */
    private const string EXTENSION = '.md';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory  $directory
     */
    public function __construct(

        /** The docs directory the files are read from. */
        private DocsDirectory $directory = new DocsDirectory,
    ) {}

    /**
     * List every file of the kind, shared ones first, then each audience's.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind  $kind
     * @return list<string>
     */
    public function files(DocKind $kind): array
    {
        $files = $this->directory->markdownIn($this->kindDirectory($this->directory->root(), $kind));

        foreach ($this->directory->audienceDirectories() as $audience) {
            array_push($files, ...$this->directory->markdownIn($this->kindDirectory($this->directory->audienceRoot($audience), $kind)));
        }

        return $files;
    }

    /**
     * Resolve the audience's descriptions, failing on any file matching no key.
     *
     * Every exported audience's files are audited, so an export fails on any
     * orphan, whichever audience it is for. A present but empty file resolves
     * to an empty string.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind  $kind
     * @param  array<array-key, list<string>>  $keysByAudience
     * @param  string  $audience
     * @return array<string, string>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    public function resolve(DocKind $kind, array $keysByAudience, string $audience): array
    {
        $shared    = $this->filesByName($this->directory->root(), $kind);
        $audiences = array_map('strval', array_keys($keysByAudience));

        $this->assertMatched($kind, $shared, array_merge(...array_values($keysByAudience)), null);

        foreach ($audiences as $name) {
            $this->assertMatched($kind, $this->filesByName($this->directory->audienceRoot($name), $kind), $keysByAudience[$name], $name);
        }

        $this->assertNoUnknownAudience($kind, $audiences);

        return $this->read($keysByAudience[$audience] ?? [], $this->filesByName($this->directory->audienceRoot($audience), $kind) + $shared);
    }

    /**
     * Read the file each key matches, keyed by the key.
     *
     * @param  list<string>  $keys
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function read(array $keys, array $files): array
    {
        $resolved = [];

        foreach ($keys as $key) {

            $file = $files[$this->encode($key)] ?? null;

            if ($file === null) {
                continue;
            }

            $resolved[$key] = $this->directory->read($file);
        }

        return $resolved;
    }

    /**
     * Assert that every file matches one of the keys.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind  $kind
     * @param  array<string, string>  $files
     * @param  list<string>  $keys
     * @param  string|null  $audience
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    private function assertMatched(DocKind $kind, array $files, array $keys, ?string $audience): void
    {
        $names = array_flip(array_map($this->encode(...), $keys));

        foreach ($files as $name => $file) {

            if (array_key_exists($name, $names)) {
                continue;
            }

            $message = $audience === null
                ? sprintf(self::SHARED_ORPHAN_MESSAGE, $kind->noun(), $file, $kind->noun())
                : sprintf(self::AUDIENCE_ORPHAN_MESSAGE, $kind->noun(), $file, $kind->noun(), $audience);

            throw new OrphanedDocumentationException($message);
        }
    }

    /**
     * Assert that no directory of an audience outside the export holds a file
     * of the kind.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind  $kind
     * @param  list<string>  $audiences
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    private function assertNoUnknownAudience(DocKind $kind, array $audiences): void
    {
        foreach (array_diff($this->directory->audienceDirectories(), $audiences) as $audience) {

            $files = $this->directory->markdownIn($this->kindDirectory($this->directory->audienceRoot($audience), $kind));

            if ($files === []) {
                continue;
            }

            throw new OrphanedDocumentationException(sprintf(self::UNKNOWN_AUDIENCE_MESSAGE, $kind->noun(), $files[0], $audience));
        }
    }

    /**
     * Map each file of the kind under the directory by its encoded key.
     *
     * @param  string  $directory
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind  $kind
     * @return array<string, string>
     */
    private function filesByName(string $directory, DocKind $kind): array
    {
        $files = [];

        foreach ($this->directory->markdownIn($this->kindDirectory($directory, $kind)) as $file) {
            $files[basename($file, self::EXTENSION)] = $file;
        }

        return $files;
    }

    /**
     * Resolve the directory holding the kind's files under the given root.
     *
     * @param  string  $root
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind  $kind
     * @return string
     */
    private function kindDirectory(string $root, DocKind $kind): string
    {
        return rtrim($root, '/') . '/' . $kind->value;
    }

    /**
     * Encode a key as a filename, percent-encoding its reserved bytes and a
     * leading dot.
     *
     * @param  string  $key
     * @return string
     */
    private function encode(string $key): string
    {
        $encoded = preg_replace_callback(
            self::RESERVED_BYTES,
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $key,
        ) ?? $key;

        return str_starts_with($encoded, '.') ? '%2E' . substr($encoded, 1) : $encoded;
    }
}
