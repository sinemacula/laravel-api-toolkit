<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Composer\Autoload\ClassLoader;
use SineMacula\ApiToolkit\Exceptions\ApiException;

/**
 * Discovers application and module defined ApiException subclasses.
 *
 * Scans a set of PSR-4 namespace roots for ApiException subclasses declaring a
 * CODE constant, so the error catalogue documents every exception an
 * application throws, not only the toolkit's own. The prefix-to-directories map
 * is injected so the source can be driven from the registered Composer
 * autoloader in production and pointed at a controlled directory under test.
 * Vendor roots are excluded, restricting the scan to the application, its
 * modules, and any local path packages.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ApiExceptionDiscoverer
{
    /**
     * Create a new discoverer for the given PSR-4 roots.
     *
     * @param  array<string, array<int, string>>  $prefixes
     * @return void
     */
    public function __construct(

        /** PSR-4 namespace prefix to source directories to scan. */
        private array $prefixes,
    ) {}

    /**
     * Build a discoverer from the registered Composer autoloader.
     *
     * Locates the ClassLoader among the registered SPL autoloaders and reads
     * its public PSR-4 prefix map. Vendor directories are filtered at scan
     * time, so the full prefix map is handed over untouched.
     *
     * @return self
     */
    public static function fromComposer(): self
    {
        $loader = self::locateClassLoader();

        return new self($loader?->getPrefixesPsr4() ?? []);
    }

    /**
     * Discover every ApiException subclass across the configured roots.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    public function discover(): array
    {
        $classes = [];

        foreach ($this->prefixes as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                foreach ($this->scan($prefix, $dir) as $class) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Locate the Composer ClassLoader among the registered SPL autoloaders.
     *
     * @return \Composer\Autoload\ClassLoader|null
     */
    private static function locateClassLoader(): ?ClassLoader
    {
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
                return $autoloader[0];
            }
        }

        return null;
    }

    /**
     * Scan one PSR-4 root, returning the qualifying exception classes.
     *
     * A root whose real path sits inside a vendor directory is skipped so only
     * the application and its modules are scanned.
     *
     * @param  string  $prefix
     * @param  string  $dir
     * @return array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    private function scan(string $prefix, string $dir): array
    {
        $root = realpath($dir);

        if ($root === false || str_contains($root, '/vendor/')) {
            return [];
        }

        $classes = [];

        foreach ($this->phpFiles($root) as $file) {

            $class = $this->classFromPath($prefix, $root, $file);

            if (!$this->qualifies($class, $file)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * List every PHP file beneath the given root as a real path.
     *
     * @param  string  $root
     * @return array<int, string>
     */
    private function phpFiles(string $root): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $real = $file->getRealPath();

            if ($real === false) {
                continue; // @codeCoverageIgnore
            }

            $files[] = $real;
        }

        sort($files);

        return $files;
    }

    /**
     * Derive the candidate FQCN for a file from its PSR-4 root.
     *
     * @param  string  $prefix
     * @param  string  $root
     * @param  string  $file
     * @return string
     */
    private function classFromPath(string $prefix, string $root, string $file): string
    {
        $relative = substr($file, strlen($root) + 1, -4);

        return rtrim($prefix, '\\') . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }

    /**
     * Determine whether the candidate is an ApiException subclass with a CODE.
     *
     * Autoloading is suppressed for a file already loaded so a non-class file -
     * such as one declaring only functions - is never re-included, and any
     * error raised while autoloading a candidate leaves it unqualified rather
     * than aborting the scan.
     *
     * @param  string  $class
     * @param  string  $file
     * @return bool
     *
     * @phpstan-assert-if-true class-string<\SineMacula\ApiToolkit\Exceptions\ApiException> $class
     *
     * @SuppressWarnings("php:S1181")
     */
    private function qualifies(string $class, string $file): bool
    {
        $autoload = !in_array($file, get_included_files(), true);

        try {
            if (!class_exists($class, $autoload)) {
                return false;
            }
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return false;
        }

        return is_subclass_of($class, ApiException::class) && defined($class . '::CODE');
    }
}
