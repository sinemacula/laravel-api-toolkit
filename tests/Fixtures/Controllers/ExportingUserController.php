<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use SineMacula\ApiToolkit\Http\Routing\Controller;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture controller answering through the base controller's resource helpers,
 * each with its own status and header.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportingUserController extends Controller
{
    /** @var string The header each action sets */
    public const string HEADER = 'X-Fixture-Action';

    /**
     * Respond with every user.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index(): Response
    {
        return $this->respondWithCollection(ExportableUserResource::collection(User::query()->paginate()), HttpStatus::ACCEPTED, [self::HEADER => 'index']);
    }

    /**
     * Respond with the given user.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function show(User $user): Response
    {
        return $this->respondWithItem(new ExportableUserResource($user), HttpStatus::CREATED, [self::HEADER => 'show']);
    }

    /**
     * Respond with every user through a resource with no tabular schema.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function basic(): Response
    {
        return $this->respondWithCollection(UserResource::collection(User::query()->paginate()));
    }
}
