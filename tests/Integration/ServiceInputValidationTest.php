<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Input\InputData;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\ValidatingUserService;
use Tests\TestCase;

/**
 * Integration tests for typed input validation on the real service path.
 *
 * Proves the headline contract: a typed InputData is validated inside the
 * lifecycle, and a validation failure is captured in the total result without
 * ever throwing out of run(). The failed run opens no transaction and writes no
 * row, while a valid run reaches handle() and commits.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
#[CoversClass(InputData::class)]
final class ServiceInputValidationTest extends TestCase
{
    /**
     * Test that a failed typed-input validation is captured without throwing.
     *
     * The service declares SampleInput as its input and receives a raw snapshot
     * missing the required city. run() must not throw; the result reports
     * failure carrying the ValidationException, and no users row is written
     * because handle() never ran.
     *
     * @return void
     */
    public function testInvalidTypedInputIsCapturedWithoutThrowing(): void
    {
        $service = new ValidatingUserService(new ArrayInput(['age' => 30]));

        $result = $service->run();

        self::assertTrue($result->failed());

        $exception = $result->exception;

        self::assertInstanceOf(ValidationException::class, $exception);
        self::assertArrayHasKey('city', $exception->errors());
        self::assertDatabaseCount('users', 0);
    }

    /**
     * Test that a valid typed input reaches handle() and commits its write.
     *
     * Proves the same path succeeds when the input satisfies the rules: the
     * validated city threads through to the output and the row is committed.
     *
     * @return void
     */
    public function testValidTypedInputReachesHandleAndCommits(): void
    {
        $service = new ValidatingUserService(new ArrayInput(['city' => 'London', 'age' => 30]));

        $result = $service->run();

        self::assertTrue($result->succeeded());
        self::assertSame('London', $result->output());
        self::assertDatabaseCount('users', 1);
        self::assertDatabaseHas('users', ['name' => 'London']);
    }
}
