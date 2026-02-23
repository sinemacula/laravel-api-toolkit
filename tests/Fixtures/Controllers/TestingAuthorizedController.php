<?php

namespace Tests\Fixtures\Controllers;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller for testing authorization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TestingAuthorizedController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = User::class;

    /** @var string */
    public const string ROUTE_PARAMETER = 'user';

    /** @var array<int, string> */
    public const array GUARD_EXCLUSIONS = ['index', 'show'];
}
