<?php

namespace Tests\Unit\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use Illuminate\Support\MessageBag;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Http\FormRequest;
use Tests\TestCase;

/**
 * Tests for the FormRequest base class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FormRequest::class)]
class FormRequestTest extends TestCase
{
    /**
     * Test that FormRequest extends Laravel's FormRequest.
     *
     * @return void
     */
    public function testExtendsLaravelFormRequest(): void
    {
        static::assertContains(LaravelFormRequest::class, class_parents(FormRequest::class) ?: []);
    }

    /**
     * Test that failedValidation throws InvalidInputException with validator messages.
     *
     * @return void
     */
    public function testFailedValidationThrowsInvalidInputException(): void
    {
        $messages   = ['email' => ['The email field is required.']];
        $messageBag = new MessageBag($messages);

        $validator = $this->createMock(Validator::class);
        $validator->method('getMessageBag')->willReturn($messageBag);

        $formRequest = $this->createConcreteFormRequest();

        $reflection = new \ReflectionMethod($formRequest, 'failedValidation');

        try {
            $reflection->invoke($formRequest, $validator);
            static::fail('Expected InvalidInputException was not thrown.');
        } catch (\Throwable $exception) {
            static::assertInstanceOf(InvalidInputException::class, $exception);
            static::assertSame($messages, $exception->getCustomMeta());
        }
    }

    /**
     * Test that failedValidation throws InvalidInputException with empty messages.
     *
     * @return void
     */
    public function testFailedValidationThrowsWithEmptyMessages(): void
    {
        $messageBag = new MessageBag;

        $validator = $this->createMock(Validator::class);
        $validator->method('getMessageBag')->willReturn($messageBag);

        $formRequest = $this->createConcreteFormRequest();

        $reflection = new \ReflectionMethod($formRequest, 'failedValidation');

        $this->expectException(InvalidInputException::class);

        $reflection->invoke($formRequest, $validator);
    }

    /**
     * Test that failedValidation passes multiple field errors.
     *
     * @return void
     */
    public function testFailedValidationPassesMultipleFieldErrors(): void
    {
        $messages = [
            'email'    => ['The email field is required.'],
            'password' => ['The password must be at least 8 characters.', 'The password must contain a number.'],
        ];

        $messageBag = new MessageBag($messages);

        $validator = $this->createMock(Validator::class);
        $validator->method('getMessageBag')->willReturn($messageBag);

        $formRequest = $this->createConcreteFormRequest();

        $reflection = new \ReflectionMethod($formRequest, 'failedValidation');

        try {
            $reflection->invoke($formRequest, $validator);
            static::fail('Expected InvalidInputException was not thrown.');
        } catch (\Throwable $exception) {
            static::assertInstanceOf(InvalidInputException::class, $exception);
            static::assertSame($messages, $exception->getCustomMeta());
        }
    }

    /**
     * Create a concrete implementation of the abstract FormRequest.
     *
     * @return \SineMacula\ApiToolkit\Http\FormRequest
     */
    private function createConcreteFormRequest(): FormRequest
    {
        return new class extends FormRequest {
            /**
             * Get the validation rules.
             *
             * @return array<string, mixed>
             */
            public function rules(): array
            {
                return [];
            }
        };
    }
}
