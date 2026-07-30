<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Composer\Autoload\ClassLoader;

/**
 * Exposes the application's non-vendor PSR-4 namespace roots.
 *
 * Reads the registered Composer autoloader's PSR-4 prefix map and filters out
 * the vendor tree, restricting the roots to the application, its modules, and
 * any local path packages. The scanning collaborator reads the existing
 * directories to walk, while the module resolver reads the prefixes to match a
 * class against its owning root. Centralising the acquisition here keeps the
 * ClassLoader access and the vendor filtering in one place rather than
 * duplicated across every consumer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class Psr4RootMap
{
    /** @var string The path fragment marking a directory as vendored. */
    private const string VENDOR = '/vendor/';

    /**
     * Create a new PSR-4 root map from a prefix to directories map.
     *
     * @param  array<string, array<int, string>>  $prefixes
     * @return void
     */
    public function __construct(

        /** PSR-4 namespace prefix to source directories. */
        private array $prefixes,
    ) {}

    /**
     * Build a root map from the registered Composer autoloader.
     *
     * Locates the ClassLoader among the registered SPL autoloaders and reads
     * its public PSR-4 prefix map, yielding an empty map when none is found.
     *
     * @return self
     */
    public static function fromComposer(): self
    {
        $loader = self::locateClassLoader();

        return new self($loader?->getPrefixesPsr4() ?? []);
    }

    /**
     * Return the raw PSR-4 prefix to directories map.
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        return $this->prefixes;
    }

    /**
     * Return the prefix to real directories map, keeping only existing,
     * non-vendor directories.
     *
     * @return array<string, array<int, string>>
     */
    public function roots(): array
    {
        $roots = [];

        foreach ($this->prefixes as $prefix => $dirs) {

            $kept = $this->realNonVendorDirs($dirs);

            if ($kept === []) {
                continue;
            }

            $roots[$prefix] = $kept;
        }

        return $roots;
    }

    /**
     * Return the non-vendor namespace prefixes, longest first.
     *
     * Trailing separators are trimmed so a prefix can be matched against a
     * class name, and the longest-first order lets a consumer take the most
     * specific matching root.
     *
     * @return array<int, string>
     */
    public function prefixes(): array
    {
        $prefixes = [];

        foreach ($this->prefixes as $prefix => $dirs) {
            if (!$this->hasNonVendorDir($dirs)) {
                continue;
            }

            $prefixes[] = rtrim($prefix, '\\');
        }

        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    /**
     * Locate the Composer ClassLoader among the registered SPL autoloaders.
     *
     * @return \Composer\Autoload\ClassLoader|null
     */
    private static function locateClassLoader(): ?ClassLoader
    {
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                return $autoloader[0];
            }
        }

        return null;
    }

    /**
     * Reduce a directory list to its existing, non-vendor real paths.
     *
     * @param  array<int, string>  $dirs
     * @return array<int, string>
     */
    private function realNonVendorDirs(array $dirs): array
    {
        $kept = [];

        foreach ($dirs as $dir) {

            $real = realpath($dir);

            if ($real === false || str_contains($real, self::VENDOR)) {
                continue;
            }

            $kept[] = $real;
        }

        return $kept;
    }

    /**
     * Determine whether any of the given directories is non-vendor, resolving
     * each to its real path where it exists so a synthetic root is honoured.
     *
     * @param  array<int, string>  $dirs
     * @return bool
     */
    private function hasNonVendorDir(array $dirs): bool
    {
        foreach ($dirs as $dir) {

            $real = realpath($dir);
            $path = $real === false ? $dir : $real;

            if (!str_contains($path, self::VENDOR)) {
                return true;
            }
        }

        return false;
    }
}
