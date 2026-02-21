<?php

namespace SineMacula\ApiToolkit\Support\Discovery;

/**
 * Scans configured roots and resolves loadable class names.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
class ClassScanner
{
    /**
     * Discover classes from a set of roots.
     *
     * @param  array<int, array{path: string, namespace: string}>  $roots
     * @return array<string, string>
     */
    public function discover(array $roots): array
    {
        $classes = [];

        foreach ($roots as $root) {
            $root_classes = $this->discoverFromRoot($root['path'], $root['namespace']);

            foreach ($root_classes as $class) {
                $classes[$class] = $root['namespace'];
            }
        }

        return $classes;
    }

    /**
     * Discover loadable classes from one root.
     *
     * @param  string  $path
     * @param  string  $namespace
     * @return array<int, string>
     */
    private function discoverFromRoot(string $path, string $namespace): array
    {
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file, $path, $namespace);

            if ($class !== null && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Resolve a class name from a root file path.
     *
     * @param  \SplFileInfo  $file
     * @param  string  $root_path
     * @param  string  $root_namespace
     * @return string|null
     */
    private function classFromFile(\SplFileInfo $file, string $root_path, string $root_namespace): ?string
    {
        $real_path = $file->getRealPath();

        if ($real_path === false) {
            return null;
        }

        $relative = ltrim(str_replace($root_path, '', $real_path), DIRECTORY_SEPARATOR);

        if (!str_ends_with($relative, '.php')) {
            return null;
        }

        $relative_class = str_replace(['/', '\\'], '\\', substr($relative, 0, -4));

        return $root_namespace . $relative_class;
    }
}
