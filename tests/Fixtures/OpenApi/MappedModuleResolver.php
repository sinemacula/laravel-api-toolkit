<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;

/**
 * Stub module resolver mapping classes to predetermined modules.
 *
 * Lets a documentation generator test drive grouping directly from a supplied
 * class to module map, so the rendering is exercised in isolation from the
 * namespace-key detection. A class absent from the map resolves to no module,
 * so an empty map reproduces the flat, combined output.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class MappedModuleResolver implements ModuleResolver
{
    /**
     * Create a new mapped module resolver.
     *
     * @param  array<string, \SineMacula\ApiToolkit\OpenApi\Docs\Module>  $map
     * @return void
     */
    public function __construct(

        /** Class name to the module it resolves to. */
        private array $map = [],
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
        return $this->map[$class] ?? null;
    }
}
