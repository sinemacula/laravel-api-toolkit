<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\Gate;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Models\Post;

/**
 * Fixture service that retitles a post once the gate lets its actor update it.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RetitlePostService extends Service
{
    /**
     * Authorize the actor to update the post.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[\Override]
    protected function authorize(): void
    {
        Gate::forUser($this->actor()->toAuthenticatable())->authorize('update', $this->post());
    }

    /**
     * Write the new title.
     *
     * @return string
     */
    #[\Override]
    protected function handle(): string
    {
        $title = $this->input->string('title');

        $this->post()->update(['title' => $title]);

        return $title;
    }

    /**
     * Load the post the input names.
     *
     * @return \Tests\Fixtures\Models\Post
     */
    private function post(): Post
    {
        return Post::query()->findOrFail($this->input->integer('post'));
    }
}
