<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Repositories;

use Illuminate\Contracts\Foundation\Application;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;

/**
 * Fixture deferrable repository over the model class it is built with.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @extends \SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model>
 */
final class DeferrableModelRepository extends ApiRepository
{
    use Deferrable;

    /**
     * Create a new deferrable model repository instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return void
     */
    public function __construct(

        // The application the repository resolves its collaborators from
        Application $app,

        /** The model class the repository writes. */
        private readonly string $modelClass,
    ) {
        parent::__construct($app);
    }

    /**
     * Return the model class.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    #[\Override]
    public function model(): string
    {
        return $this->modelClass;
    }
}
