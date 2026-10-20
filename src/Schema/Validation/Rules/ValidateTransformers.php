<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Enums\CallableList;

/**
 * Validate that all transformer entries in the compiled schema are callable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateTransformers extends ValidatesCallableLists
{
    /**
     * Return the callable list this rule validates.
     *
     * @return \SineMacula\ApiToolkit\Enums\CallableList
     */
    #[\Override]
    protected function getCallableList(): CallableList
    {
        return CallableList::TRANSFORMERS;
    }
}
