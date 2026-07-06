<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Invalid;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use Tests\Fixtures\Models\User;

/**
 * Fixture abstract resource with a model binding; discovery must skip it with
 * a warning because it is not instantiable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ForModel(User::class)]
abstract class AbstractAttributedResource extends ApiResource {}
