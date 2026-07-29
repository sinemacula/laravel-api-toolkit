<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

use SineMacula\ApiToolkit\OpenApi\Docs\Module;

/**
 * Resolves the documentation module a class belongs to.
 *
 * The seam behind which module detection lives, so the generated documentation
 * can be grouped by module when an application is modularised and fall back to
 * a single shared section when it is not. An application may bind its own
 * implementation to override the default namespace-key detection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ModuleResolver
{
    /**
     * Resolve the module the given class belongs to, or null when it belongs to
     * none.
     *
     * @param  string  $class
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\Module|null
     */
    public function resolve(string $class): ?Module;
}
