<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use SineMacula\ApiToolkit\ApiQueryParser;
use Tests\Fixtures\Exceptions\JobAttemptException;

/**
 * Fixture job that parses a query and then ends its attempt the given way.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParsingJob implements ShouldQueue
{
    use InteractsWithQueue;

    /** @var string The attempt deletes the job */
    public const string DELETE = 'delete';

    /** @var string The attempt throws */
    public const string THROW = 'throw';

    /** @var string The attempt fails the job itself */
    public const string FAIL = 'fail';

    /** @var \SineMacula\ApiToolkit\ApiQueryParser|null The parser the last attempt parsed into */
    public static ?ApiQueryParser $parser = null;

    /**
     * Create a new parsing job instance.
     *
     * @param  string  $ending
     * @return void
     */
    public function __construct(

        /** How the attempt ends once the query is parsed */
        public readonly string $ending,
    ) {}

    /**
     * Parse a query, then end the attempt.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     * @throws \Tests\Fixtures\Exceptions\JobAttemptException
     */
    public function handle(): void
    {
        $parser = app('api.query');

        assert($parser instanceof ApiQueryParser);

        $parser->parse(Request::create('/users', 'GET', ['fields' => ['users' => 'name,email']]));

        self::$parser = $parser;

        match ($this->ending) {
            self::DELETE => $this->delete(),
            self::FAIL   => $this->fail(new JobAttemptException('The job failed itself.')),
            default      => throw new JobAttemptException('The attempt failed.'),
        };
    }
}
