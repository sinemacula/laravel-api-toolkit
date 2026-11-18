<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture controller that declares its middleware, so the router builds it
 * before the route's middleware runs, and composes the API criteria as it is
 * built.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CriteriaComposingController extends Controller
{
    /** @var \Tests\Fixtures\Repositories\UserRepository The users, with the API criteria composed */
    private readonly UserRepository $users;

    /**
     * Constructor.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $users
     * @return void
     */
    public function __construct(UserRepository $users)
    {
        $this->users = $users->usingResource(UserResource::class)->withApiCriteria();
    }

    /**
     * List the names of the users the API query selects.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        return new JsonResponse($this->users->get()->pluck('name')->all()); // @phpstan-ignore staticMethod.dynamicCall
    }
}
