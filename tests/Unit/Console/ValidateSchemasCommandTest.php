<?php

namespace Tests\Unit\Console;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ValidateSchemasCommand;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Services\SchemaValidator;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateSchemasCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateSchemasCommand::class)]
class ValidateSchemasCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:validate-schemas';

    /**
     * Test that the command reports success for valid schemas.
     *
     * @return void
     */
    public function testCommandReportsSuccessForValidSchemas(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([]);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator($rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $this->artisan(self::COMMAND) // @phpstan-ignore method.nonObject
            ->expectsOutputToContain('All 1 resource schema(s) validated successfully.')
            ->assertExitCode(0);
    }

    /**
     * Test that the command reports failure for invalid schemas.
     *
     * @return void
     */
    public function testCommandReportsFailureForInvalidSchemas(): void
    {
        $error = new SchemaValidationError(UserResource::class, 'id', 'Test defect');

        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([$error]);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator($rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $this->artisan(self::COMMAND) // @phpstan-ignore method.nonObject
            ->expectsOutputToContain('Schema validation failed:')
            ->assertExitCode(1);
    }

    /**
     * Test that the command warns when no resources are registered.
     *
     * @return void
     */
    public function testCommandWarnsWhenNoResourcesRegistered(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->artisan(self::COMMAND) // @phpstan-ignore method.nonObject
            ->expectsOutputToContain('No resources registered in the resource map.')
            ->assertExitCode(0);
    }

    /**
     * Test that the command output contains error details.
     *
     * @return void
     */
    public function testCommandOutputContainsErrorDetails(): void
    {
        $errors = [
            new SchemaValidationError(UserResource::class, 'id', 'First defect'),
            new SchemaValidationError(UserResource::class, 'name', 'Second defect'),
        ];

        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn($errors);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator($rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $this->artisan(self::COMMAND) // @phpstan-ignore method.nonObject
            ->expectsOutputToContain((string) $errors[0])
            ->expectsOutputToContain((string) $errors[1])
            ->assertExitCode(1);
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->getApplication()->make('config');
    }
}
