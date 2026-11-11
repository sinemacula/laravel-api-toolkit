<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Enums\Capability;

/**
 * Narrows a capability's operator set to the tokens the package can dispatch.
 *
 * The filter engine refuses an unbound token as an undeclared key, so
 * documenting it would advertise a predicate every request refuses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DispatchableOperators
{
    /**
     * Return the capability's operators the vocabulary holds, in capability
     * order.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  array<int, string>  $vocabulary
     * @return array<int, string>
     */
    public static function forCapability(Capability $capability, array $vocabulary): array
    {
        return array_values(array_filter(
            $capability->permittedOperators(),
            static fn (string $token): bool => in_array($token, $vocabulary, true),
        ));
    }
}
