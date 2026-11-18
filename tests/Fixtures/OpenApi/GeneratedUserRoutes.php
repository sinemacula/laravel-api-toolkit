<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Routing\Router;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Registers any number of named user routes, four operations over two paths at
 * a time, for exports at application scale.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GeneratedUserRoutes
{
    /** @var array<class-string<\Illuminate\Database\Eloquent\Model>, class-string> The resources the operations reference */
    public const array RESOURCE_MAP = [
        User::class         => UserResource::class,
        Organization::class => OrganizationResource::class,
        Post::class         => PostResource::class,
        Tag::class          => TagResource::class,
    ];

    /** @var list<array{string, string, string}> The verb, path suffix and action of each operation in a group */
    private const array OPERATIONS = [
        ['GET', '', 'index'],
        ['POST', '', 'store'],
        ['GET', '/{user}', 'show'],
        ['DELETE', '/{user}', 'destroy'],
    ];

    /**
     * Register the given number of routes after those already generated.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @param  int  $from
     * @param  int  $count
     * @return void
     */
    public static function register(Router $router, int $from, int $count): void
    {
        for ($index = $from; $index < $from + $count; $index++) {

            $group = 'users-' . intdiv($index, count(self::OPERATIONS));

            [$verb, $suffix, $action] = self::OPERATIONS[$index % count(self::OPERATIONS)];

            $router->addRoute($verb, $group . $suffix, [PathFixtureController::class, $action])->name($group . '.' . $action);
        }
    }
}
