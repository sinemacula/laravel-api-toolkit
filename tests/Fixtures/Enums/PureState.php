<?php

namespace Tests\Fixtures\Enums;

use SineMacula\ApiToolkit\Contracts\PureEnumInterface;
use SineMacula\ApiToolkit\Enums\Traits\PureEnumHelper;

/**
 * Fixture pure enum implementing the PureEnumInterface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum PureState implements PureEnumInterface
{
    use PureEnumHelper;

    case PENDING;
    case APPROVED;
    case REJECTED;
}
