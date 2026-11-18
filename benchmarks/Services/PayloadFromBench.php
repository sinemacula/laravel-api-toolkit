<?php

declare(strict_types = 1);

namespace Benchmarks\Services;

use Benchmarks\Support\BenchmarkApplication;
use Benchmarks\Support\ProfilePayload;
use Illuminate\Http\Request;
use PhpBench\Attributes as Bench;

/**
 * Benchmarks validating and hydrating a ten-field payload with enums from an
 * array and from a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(100)]
final class PayloadFromBench
{
    /** @var array<string, string> The submitted body */
    private const array BODY = [
        'name'     => 'Ada Lovelace',
        'email'    => 'ada@example.com',
        'phone'    => '+44 20 7946 0000',
        'city'     => 'London',
        'country'  => 'GB',
        'bio'      => 'Mathematician and writer.',
        'status'   => 'active',
        'priority' => 'high',
        'level'    => '2',
        'platform' => 'mobile_app',
    ];

    /** @var \Illuminate\Http\Request The request carrying the body */
    private Request $request;

    /**
     * Boot the application and build the request.
     *
     * @return void
     */
    public function setUp(): void
    {
        BenchmarkApplication::create();

        $this->request = Request::create('/profiles', 'POST', self::BODY);

        ProfilePayload::from(self::BODY);
    }

    /**
     * Hydrate the payload from the array.
     *
     * @return void
     */
    public function benchFromArray(): void
    {
        ProfilePayload::from(self::BODY);
    }

    /**
     * Hydrate the payload from the request.
     *
     * @return void
     */
    public function benchFromRequest(): void
    {
        ProfilePayload::from($this->request);
    }
}
