<?php

namespace Tests\Unit\Concerns;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use Tests\TestCase;

/**
 * Tests for the QueryParameterValidator concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryParameterValidator::class)]
final class QueryParameterValidatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterValidator */
    private QueryParameterValidator $validator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new QueryParameterValidator;
    }

    /**
     * Provide parameter sets that pass validation.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validParameterProvider(): iterable
    {
        yield 'no parameters' => [[]];
        yield 'fields string' => [['fields' => 'name,email']];
        yield 'fields array of strings' => [['fields' => ['user' => 'name,email']]];
        yield 'counts array of strings' => [['counts' => ['user' => 'posts,comments']]];
        yield 'sums nested arrays of strings' => [['sums' => ['account' => ['transaction' => 'amount']]]];
        yield 'averages nested arrays of strings' => [['averages' => ['account' => ['transaction' => 'amount']]]];
        yield 'valid json filters' => [['filters' => '{"status":"active"}']];
        yield 'order string' => [['order' => 'name:asc']];
        yield 'page of one' => [['page' => '1']];
        yield 'limit of one' => [['limit' => '1']];
        yield 'cursor string' => [['cursor' => 'eyJpZCI6MTAwfQ==']];
    }

    /**
     * Test that valid parameters pass validation without an exception.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    #[DataProvider('validParameterProvider')]
    public function testValidParametersPassValidation(array $parameters): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($parameters);
    }

    /**
     * Provide parameter sets that fail validation.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidParameterProvider(): iterable
    {
        yield 'non-numeric page' => [['page' => 'not-a-number']];
        yield 'page below one' => [['page' => '0']];
        yield 'non-numeric limit' => [['limit' => 'abc']];
        yield 'limit below one' => [['limit' => '0']];
        yield 'invalid json filters' => [['filters' => 'not-valid-json{']];
        yield 'integer fields' => [['fields' => 123]];
        yield 'array order' => [['order' => ['name' => 'asc']]];
        yield 'array cursor' => [['cursor' => ['id' => 100]]];
        yield 'array fields resource value' => [['fields' => ['user' => ['name', 'email']]]];
        yield 'array counts resource value' => [['counts' => ['user' => ['posts', 'comments']]]];
        yield 'string sums resource value' => [['sums' => ['account' => 'amount']]];
        yield 'string averages resource value' => [['averages' => ['account' => 'amount']]];
        yield 'integer sums field value' => [['sums' => ['account' => ['transaction' => 42]]]];
        yield 'integer averages field value' => [['averages' => ['account' => ['transaction' => 42]]]];
    }

    /**
     * Test that invalid parameters fail validation with an exception.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    #[DataProvider('invalidParameterProvider')]
    public function testInvalidParametersFailValidation(array $parameters): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate($parameters);
    }
}
