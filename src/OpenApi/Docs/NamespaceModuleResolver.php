<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Metadata\Psr4RootMap;

/**
 * Detects a class's module from its namespace, keyed by the PSR-4 roots.
 *
 * A class's module is its longest matching non-vendor PSR-4 root prefix plus
 * the namespace segments between that root and the first framework-vocabulary
 * segment, e.g. "Verifast\User\Http\Controllers\ProfileController" resolves to
 * "Verifast\User". A class whose first segment beneath the root is already
 * framework vocabulary, or that matches no non-vendor root, belongs to no
 * module and renders in the shared section. A flat application therefore
 * resolves every class to no module, which is the single combined section as
 * the degenerate case of the same algorithm rather than a separate mode.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class NamespaceModuleResolver implements ModuleResolver
{
    /** @var array<int, string> The framework-vocabulary segments that bound a module. */
    private const array FRAMEWORK_VOCAB = [
        'Http', 'Console', 'Models', 'Model', 'Exceptions', 'Enums', 'Events',
        'Listeners', 'Jobs', 'Notifications', 'Mail', 'Policies', 'Providers',
        'Observers', 'Resources', 'Rules', 'Casts', 'Actions', 'Services',
        'Repositories', 'Requests', 'Middleware', 'Contracts', 'Broadcasting',
    ];

    /**
     * Create a new namespace module resolver.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\Psr4RootMap  $roots
     * @return void
     */
    public function __construct(

        /** Supplies the non-vendor PSR-4 roots to match a class against. */
        private Psr4RootMap $roots,
    ) {}

    /**
     * Resolve the module the given class belongs to, or null when it belongs to
     * none.
     *
     * @param  string  $class
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\Module|null
     */
    #[\Override]
    public function resolve(string $class): ?Module
    {
        $root = $this->matchRoot($class);

        if ($root === null) {
            return null;
        }

        return $this->moduleFrom($root, substr($class, strlen($root) + 1));
    }

    /**
     * Match the class to its longest non-vendor root prefix, or null when none
     * matches.
     *
     * @param  string  $class
     * @return string|null
     */
    private function matchRoot(string $class): ?string
    {
        foreach ($this->roots->prefixes() as $prefix) {
            if (str_starts_with($class, $prefix . '\\')) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Build the module from the matched root and the class's remaining
     * namespace, or null when no module segment precedes the first framework
     * segment.
     *
     * @param  string  $root
     * @param  string  $remainder
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\Module|null
     */
    private function moduleFrom(string $root, string $remainder): ?Module
    {
        $segments = explode('\\', $remainder);
        $boundary = $this->frameworkBoundary($segments);

        if ($boundary <= 0) {
            return null;
        }

        $key = $root . '\\' . implode('\\', array_slice($segments, 0, $boundary));

        return new Module($key, $segments[$boundary - 1]);
    }

    /**
     * Find the index of the first framework-vocabulary segment, or -1 when the
     * segments carry none.
     *
     * @param  array<int, string>  $segments
     * @return int
     */
    private function frameworkBoundary(array $segments): int
    {
        foreach ($segments as $index => $segment) {
            if (in_array($segment, self::FRAMEWORK_VOCAB, true)) {
                return $index;
            }
        }

        return -1;
    }
}
