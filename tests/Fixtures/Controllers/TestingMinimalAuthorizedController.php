<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller with only the required attribute options.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class TestingMinimalAuthorizedController extends AuthorizedController {}
