<?php

namespace Tests\Fixtures\Controllers;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller without optional constants.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TestingMinimalAuthorizedController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = User::class;
}
