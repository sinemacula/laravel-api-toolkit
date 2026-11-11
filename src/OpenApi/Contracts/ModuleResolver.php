<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

use SineMacula\ApiToolkit\OpenApi\Docs\Module;

/**
 * Resolves the documentation module a class belongs to.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ModuleResolver
{
    /**
     * Resolve the module the given class belongs to, if any.
     *
     * @param  string  $class
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\Module|null
     */
    public function resolve(string $class): ?Module;
}
