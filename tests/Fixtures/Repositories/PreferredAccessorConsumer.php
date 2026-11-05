<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;

/**
 * Accessor fixture that prefers the repository accessor over another trait's
 * magic call handler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @method \Tests\Fixtures\Repositories\RecordRepository records()
 */
class PreferredAccessorConsumer
{
    use HandlesOtherCalls, HasRepositories {
        HasRepositories::__call insteadof HandlesOtherCalls;
    }
}
