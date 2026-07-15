<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller for testing authorization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TestingAuthorizedController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = User::class;

    /** @var string Deliberately mixed-case to assert the lowercase normalisation. */
    public const string ROUTE_PARAMETER = 'User';

    /** @var array<int, string> */
    public const array GUARD_EXCLUSIONS = ['index', 'show'];

    /**
     * List users. Excluded from the authorization guard.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    /**
     * Create a user. Guarded by the create ability.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(): JsonResponse
    {
        return new JsonResponse(['data' => []], 201);
    }
}
