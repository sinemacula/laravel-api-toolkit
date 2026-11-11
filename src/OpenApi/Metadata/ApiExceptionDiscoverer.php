<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\ApiException;

/**
 * Discovers application and module defined ApiException subclasses.
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
     * @param  array<int, string>  $extraNamespaces
     * @return void
     */
    public function __construct(

        /** PSR-4 namespace prefix to source directories to scan. */
        private array $prefixes,

        /** Extra namespace prefixes to scan even when resolved under vendor. */
        private array $extraNamespaces = [],
    ) {}

    /**
     * Build a discoverer from the registered Composer autoloader.
     *
     * The full prefix map is kept so extra namespaces can resolve under vendor.
     *
     * @return self
     */
    public static function fromComposer(): self
    {
        $configured = Config::get('api-toolkit.openapi.exception_namespaces', []);
        $namespaces = is_array($configured) ? array_filter($configured, 'is_string') : [];

        return new self(Psr4RootMap::fromComposer()->all(), $namespaces);
    }

    /**
     * Discover every ApiException subclass across the configured roots.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    public function discover(): array
    {
        $classes = [];

        foreach ($this->scanTargets() as [$prefix, $dir]) {
            foreach ($this->scan($prefix, $dir) as $class) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Resolve the prefix and directory pairs to scan.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function scanTargets(): array
    {
        $targets = [];

        foreach ((new Psr4RootMap($this->prefixes))->roots() as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                $targets[] = [$prefix, $dir];
            }
        }

        foreach ($this->extraNamespaceTargets() as $target) {
            $targets[] = $target;
        }

        return $targets;
    }

    /**
     * Resolve the prefix and real-directory pairs for the extra namespaces.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function extraNamespaceTargets(): array
    {
        $targets = [];

        foreach ($this->extraNamespaces as $namespace) {

            $prefix = rtrim($namespace, '\\') . '\\';

            foreach ($this->prefixes[$prefix] ?? [] as $dir) {

                $real = realpath($dir);

                if ($real === false) {
                    continue;
                }

                $targets[] = [$prefix, $real];
            }
        }

        return $targets;
    }

    /**
     * Scan one PSR-4 root, returning the qualifying exception classes.
     *
     * @param  string  $prefix
     * @param  string  $root
     * @return array<int, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    private function scan(string $prefix, string $root): array
    {
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
     * An already-loaded file is not autoloaded, so a function-only file is
     * never re-included.
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
