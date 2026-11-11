<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\ValidatingUserService;
use Tests\TestCase;

/**
 * Integration tests for typed input validation on the real service path.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
#[CoversClass(Payload::class)]
final class ServiceInputValidationTest extends TestCase
{
    /**
     * Test that a failed typed-input validation is captured without throwing.
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
