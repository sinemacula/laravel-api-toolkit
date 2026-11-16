<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Invoice;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Models\User;

/**
 * Fixture controller whose actions bind their path parameters by type.
 *
 * Each action returns what it was bound, so a dispatched request shows the
 * binding ran.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathBindingController
{
    /**
     * Return an unbound parameter.
     *
     * @param  string  $value
     * @return string
     */
    public function value(string $value): string
    {
        return $value;
    }

    /**
     * Return a bound user.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Tests\Fixtures\Models\User
     */
    public function user(User $user): User
    {
        return $user;
    }

    /**
     * Return a bound ticket's key.
     *
     * @param  \Tests\Fixtures\Models\Ticket  $ticket
     * @return string
     */
    public function ticket(Ticket $ticket): string
    {
        return $ticket->id;
    }

    /**
     * Return a bound user and ticket.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @param  \Tests\Fixtures\Models\Ticket  $ticket
     * @return array{0: \Tests\Fixtures\Models\User, 1: \Tests\Fixtures\Models\Ticket}
     */
    public function userTicket(User $user, Ticket $ticket): array
    {
        return [$user, $ticket];
    }

    /**
     * Return a bound post, named in camel case.
     *
     * @param  \Tests\Fixtures\Models\Post  $blogPost
     * @return \Tests\Fixtures\Models\Post
     */
    public function blogPost(Post $blogPost): Post
    {
        return $blogPost;
    }

    /**
     * Return a bound string-backed enum's value.
     *
     * @param  \Tests\Fixtures\Enums\UserStatus  $status
     * @return string
     */
    public function status(UserStatus $status): string
    {
        return $status->value;
    }

    /**
     * Return an int-backed enum, which Laravel does not bind.
     *
     * @param  \Tests\Fixtures\Enums\UserLevel  $level
     * @return \Tests\Fixtures\Enums\UserLevel
     */
    public function level(UserLevel $level): UserLevel
    {
        return $level;
    }

    /**
     * Return a bound invoice.
     *
     * @param  \Tests\Fixtures\Models\Invoice  $invoice
     * @return \Tests\Fixtures\Models\Invoice
     */
    public function invoice(Invoice $invoice): Invoice
    {
        return $invoice;
    }

    /**
     * Return a bound routable handle.
     *
     * @param  \Tests\Fixtures\OpenApi\RoutableHandle  $handle
     * @return \Tests\Fixtures\OpenApi\RoutableHandle
     */
    public function handle(RoutableHandle $handle): RoutableHandle
    {
        return $handle;
    }
}
