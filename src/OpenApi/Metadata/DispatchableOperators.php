<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Enums\Capability;

/**
 * Narrows a capability's operator set to the tokens the package can dispatch.
 *
 * A capability names the operators its access path answers, which is a claim
 * about the column rather than about the package: the token still has to be
 * bound to a handler for a request carrying it to reach the column at all. An
 * unbound token is not read as an operator by the filter engine, so it is
 * refused as an undeclared key long before the capability is consulted, and
 * documenting it would advertise a predicate every request refuses.
 *
 * The intersection keeps the capability's declaration order, so the operators a
 * column is documented with read the same way whether or not the registry has
 * been narrowed. A capability whose every token has been unbound documents no
 * filter at all rather than an empty one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DispatchableOperators
{
    /**
     * Return the operators the capability permits that the given vocabulary
     * still holds, in the capability's own order.
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
