<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Input\SampleInput;

/**
 * Fixture service that validates a typed SampleInput inside the lifecycle.
 *
 * Carries the raw request snapshot as an ArrayInput and promotes it to a typed,
 * validated SampleInput from within the validate() lifecycle hook. A missing
 * required field makes from() throw ValidationException, which the runner
 * captures as a failure before the transaction is opened, so handle() never
 * runs and the users row is never written. On valid input, handle() inserts a
 * row and returns the validated city, proving the core executed.
 *
 * @extends \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Input\ArrayInput, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidatingUserService extends Service
{
    /** @var \Tests\Fixtures\Input\SampleInput|null The validated typed input */
    private ?SampleInput $validated = null;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     */
    public function __construct(ServiceInput $input = new ArrayInput([]))
    {
        parent::__construct($input);
    }

    /**
     * Validate the raw input and promote it to a typed SampleInput.
     *
     * Runs inside the lifecycle before the lock and transaction. Throws
     * ValidationException when the source fails the SampleInput rules.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    #[\Override]
    protected function validate(): void
    {
        $this->validated = SampleInput::from($this->input->toArray());
    }

    /**
     * Insert a users row from the validated input and return the city.
     *
     * @return string
     */
    #[\Override]
    protected function handle(): mixed
    {
        assert($this->validated !== null);

        $city = $this->validated->city;

        DB::table('users')->insert([
            'name'  => $city,
            'email' => strtolower($city) . '@validated.test',
        ]);

        return $city;
    }
}
