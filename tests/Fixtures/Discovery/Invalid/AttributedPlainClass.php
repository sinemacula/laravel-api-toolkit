<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Invalid;

use SineMacula\ApiToolkit\Attributes\ForModel;
use Tests\Fixtures\Models\User;

/**
 * Fixture class that declares a model binding without being an API resource;
 * discovery must skip it with a warning.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ForModel(User::class)]
final class AttributedPlainClass {}
