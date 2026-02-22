<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

class TestingAuthorizedController extends AuthorizedController
{
    public const string RESOURCE_MODEL  = User::class;
    public const string ROUTE_PARAMETER = 'USER';
    public const array GUARD_EXCLUSIONS = ['index'];
}
