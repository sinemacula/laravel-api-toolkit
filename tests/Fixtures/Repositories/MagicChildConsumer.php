<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;

/**
 * Accessor fixture whose parent also handles magic calls.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @method \Tests\Fixtures\Repositories\RecordRepository records()
 * @method string other(string $value)
 */
class MagicChildConsumer extends MagicParent
{
    use HasRepositories;
}
