<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

use SineMacula\ApiToolkit\Contracts\PureEnumInterface;
use SineMacula\ApiToolkit\Enums\Traits\PureEnumHelper;

enum PureState implements PureEnumInterface
{
    use PureEnumHelper;

    case ENABLED;
    case DISABLED;
}
