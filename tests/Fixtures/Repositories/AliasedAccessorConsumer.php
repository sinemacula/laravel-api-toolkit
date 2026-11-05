<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;

/**
 * Accessor fixture whose magic calls go to another trait, with the
 * repository accessor kept under an alias.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @method mixed repositoryCall(string $method, array<int, mixed> $arguments)
 */
class AliasedAccessorConsumer
{
    use HandlesOtherCalls, HasRepositories {
        HandlesOtherCalls::__call insteadof HasRepositories;
        HasRepositories::__call as repositoryCall;
    }
}
