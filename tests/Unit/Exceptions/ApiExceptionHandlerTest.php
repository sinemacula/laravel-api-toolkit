<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions as ExceptionsFacade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;
use SineMacula\Http\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface as SymfonyHttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\OpenApi\PaymentRequiredException;

/**
 * Tests for the ApiExceptionHandler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ApiExceptionHandlerTest extends TestCase
{
    /** @var string Test API endpoint path. */
    private const string API_PATH = '/test';

    /** @var string JSON content type header value. */
    private const string ACCEPT_JSON = 'application/json';

    /** @var string Generic error message used in test fixtures. */
    private const string GENERIC_ERROR_MESSAGE = 'Something went wrong';

    /** @var string The message a policy denies with. */
    private const string DENIAL = 'Your trial has ended.';

    /** @var string The catalogue detail of a forbidden request. */
    private const string FORBIDDEN_DETAIL = 'You do not have the necessary privileges to perform this action';

    /** @var string The catalogue detail of an unavailable service. */
    private const string UNAVAILABLE_DETAIL = 'The service is temporarily unavailable, please try again a little later';

    /** @var \Illuminate\Foundation\Exceptions\Handler|null The handler the toolkit is registered against */
    private ?Handler $handler = null;

    /**
     * Provide exception mapping test cases.
     *
     * @return iterable<string, array{\Throwable, int, int}>
     */
    public static function exceptionMappingProvider(): iterable
    {
        yield 'NotFoundHttpException -> 404' => [
            new NotFoundHttpException,
            404,
            10103,
        ];

        yield 'LaravelUnauthorizedException -> 403' => [
            new LaravelUnauthorizedException,
            403,
            10102,
        ];

        yield 'AccessDeniedHttpException -> 403' => [
            new AccessDeniedHttpException,
            403,
            10102,
        ];

        yield 'AuthenticationException -> 401' => [
            new AuthenticationException,
            401,
            10101,
        ];

        yield 'TooManyRequestsHttpException -> 429' => [
            new TooManyRequestsHttpException,
            429,
            10107,
        ];

        yield 'MethodNotAllowedHttpException -> 405' => [
            new MethodNotAllowedHttpException(['GET', 'POST']),
            405,
            10104,
        ];

        yield 'BadRequestHttpException -> 400' => [
            new BadRequestHttpException,
            400,
            10100,
        ];

        yield 'ServiceUnavailableHttpException -> 503' => [
            new ServiceUnavailableHttpException,
            503,
            10112,
        ];

        yield 'PostTooLargeException -> 413' => [
            new PostTooLargeException,
            413,
            10110,
        ];

        yield 'PreconditionFailedHttpException -> 412' => [
            new PreconditionFailedHttpException,
            412,
            10114,
        ];

        yield 'PreconditionRequiredHttpException -> 428' => [
            new PreconditionRequiredHttpException,
            428,
            10115,
        ];

        yield 'abort(400) -> 400' => [
            new SymfonyHttpException(400),
            400,
            10100,
        ];

        yield 'abort(401) -> 401' => [
            new SymfonyHttpException(401),
            401,
            10101,
        ];

        yield 'basic-auth challenge -> 401' => [
            new UnauthorizedHttpException('Basic'),
            401,
            10101,
        ];

        yield 'abort(403) -> 403' => [
            new SymfonyHttpException(403),
            403,
            10102,
        ];

        yield 'denyAsNotFound() -> 404' => [
            new SymfonyHttpException(404),
            404,
            10103,
        ];

        yield 'abort(405) -> 405' => [
            new SymfonyHttpException(405),
            405,
            10104,
        ];

        yield 'abort(412) -> 412' => [
            new SymfonyHttpException(412),
            412,
            10114,
        ];

        yield 'abort(413) -> 413' => [
            new SymfonyHttpException(413),
            413,
            10110,
        ];

        yield 'token mismatch -> 419' => [
            new SymfonyHttpException(419),
            419,
            10105,
        ];

        yield 'abort(428) -> 428' => [
            new SymfonyHttpException(428),
            428,
            10115,
        ];

        yield 'abort(429) -> 429' => [
            new SymfonyHttpException(429),
            429,
            10107,
        ];

        yield 'abort(503) -> 503' => [
            new SymfonyHttpException(503),
            503,
            10112,
        ];

        yield 'abort(409) -> 409' => [
            new SymfonyHttpException(409),
            409,
            10113,
        ];

        yield 'abort(423) -> 423' => [
            new SymfonyHttpException(423),
            423,
            10113,
        ];

        yield 'abort(451) -> 451' => [
            new SymfonyHttpException(451),
            451,
            10113,
        ];

        yield 'HttpException with unknown status -> 500' => [
            new SymfonyHttpException(599),
            500,
            10001,
        ];

        yield 'Generic exception -> 500' => [
            new \RuntimeException(self::GENERIC_ERROR_MESSAGE),
            500,
            10001,
        ];
    }

    /**
     * Test that render maps Laravel exceptions to the correct HTTP status and
     * internal error code.
     *
     * @param  \Throwable  $inputException
     * @param  int  $expectedHttpCode
     * @param  int  $expectedErrorCode
     * @return void
     */
    #[DataProvider('exceptionMappingProvider')]
    public function testRenderMapsExceptionsCorrectly(\Throwable $inputException, int $expectedHttpCode, int $expectedErrorCode): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $response = $this->render($inputException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame($expectedHttpCode, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame($expectedHttpCode, $data['error']['status']);
        self::assertSame($expectedErrorCode, $data['error']['code']);
    }

    /**
     * Test that the generic catch-all preserves the original status code,
     * derives the title from the HTTP status, and uses the generic error code.
     *
     * @return void
     */
    public function testGenericHttpExceptionCatchAllPreservesStatusAndDerivesTitle(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $response = $this->render(new SymfonyHttpException(409), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(409, $data['error']['status']);
        self::assertSame(10113, $data['error']['code']);
        self::assertSame('Conflict', $data['error']['title']);
    }

    /**
     * Provide statuses rendered generically and as the key they stand for.
     *
     * @return iterable<string, array{int}>
     */
    public static function statusOnlyProvider(): iterable
    {
        yield 'generic' => [409];

        yield 'standing for a key' => [429];
    }

    /**
     * Test that an HTTP exception carrying only a status keeps its headers.
     *
     * @param  int  $status
     * @return void
     */
    #[DataProvider('statusOnlyProvider')]
    public function testStatusOnlyHttpExceptionKeepsItsHeaders(int $status): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $exception = new SymfonyHttpException($status, 'Slow down', null, ['Retry-After' => '120']);

        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('120', $response->headers->get('Retry-After'));
    }

    /**
     * Test that application-layer database exceptions remain 500.
     *
     * @return void
     */
    public function testUniqueConstraintViolationRemainsUnhandled(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $exception = new UniqueConstraintViolationException(
            'testing',
            'insert into users',
            [],
            new \RuntimeException('Duplicate entry'),
        );

        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that a LockUnavailableException is mapped to a 429 response.
     *
     * @return void
     */
    public function testLockUnavailableMapsTo429(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $response = $this->render(new LockUnavailableException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(429, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(429, $data['error']['status']);
        self::assertSame(10107, $data['error']['code']);
    }

    /**
     * Test that JSON rendering includes the expected error structure.
     *
     * @return void
     */
    public function testJsonRenderingIncludesErrorStructure(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $response = $this->render(new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('status', $data['error']);
        self::assertArrayHasKey('code', $data['error']);
        self::assertArrayHasKey('title', $data['error']);
        self::assertArrayHasKey('detail', $data['error']);
    }

    /**
     * Test that debug mode, spelt as an environment variable reads, includes
     * the debug details with the exception trace.
     *
     * @return void
     */
    public function testDebugModeIncludesDebugDetailsWithTrace(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', '1');

        $original = new \RuntimeException(self::GENERIC_ERROR_MESSAGE);

        $response = $this->render($original, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data['error']);
        self::assertArrayNotHasKey('meta', $data['error']);
        self::assertIsArray($data['error']['debug']);
        self::assertArrayHasKey('exception', $data['error']['debug']);
        self::assertArrayHasKey('trace', $data['error']['debug']);
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $data['error']['debug']['message']);
    }

    /**
     * Test that a non-JSON request in debug mode is left to Laravel's renderer.
     *
     * @return void
     */
    public function testNonJsonRequestInDebugModeIsLeftToLaravel(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());
        $request->headers->set('Accept', 'text/html');

        config()->set('app.debug', true);

        $response = $this->render(new \RuntimeException('error'), $request);

        self::assertLaravelHtmlServerError($response);
    }

    /**
     * Test that an already-mapped ApiException is rendered directly.
     *
     * @return void
     */
    public function testApiExceptionIsRenderedDirectly(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $exception = new BadRequestException(['field' => 'value']);

        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertSame(10100, $data['error']['code']);
    }

    /**
     * Test that ValidationException maps to 422.
     *
     * Kept separate because a Validator needs the Laravel translator.
     *
     * @return void
     */
    public function testValidationExceptionMapsToUnprocessableEntity(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $validator = new Validator(app(Translator::class), [], []);
        $exception = new ValidationException($validator);

        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(10106, $data['error']['code']);
    }

    /**
     * Test that a validation failure on a precognitive request renders the body
     * Precognition clients read, and the envelope otherwise.
     *
     * @return void
     */
    public function testPrecognitiveValidationFailureRendersMessageAndErrors(): void
    {
        config()->set('app.debug', false);

        $exception = ValidationException::withMessages(['title' => ['The title field is required.']])->status(409);

        $response = $this->render($exception, self::precognitiveRequest(self::ACCEPT_JSON));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ['message' => 'The title field is required.', 'errors' => ['title' => ['The title field is required.']]],
            $response->getData(true),
        );

        $request = Request::create(self::API_PATH, HttpMethod::POST->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['title' => ['The title field is required.']], $response->getData(true)['error']['meta']);
    }

    /**
     * Test that any other exception on a precognitive request keeps the
     * envelope.
     *
     * @return void
     */
    public function testPrecognitiveRequestKeepsTheEnvelopeForOtherExceptions(): void
    {
        config()->set('app.debug', false);

        $response = $this->render(new ConflictException(['key' => 'value']), self::precognitiveRequest(self::ACCEPT_JSON));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['key' => 'value'], $response->getData(true)['error']['meta']);
    }

    /**
     * Test that json_when_expected leaves a precognitive validation failure on
     * a non-JSON request to Laravel.
     *
     * @return void
     */
    public function testJsonWhenExpectedLeavesAPrecognitiveNonJsonRequestToLaravel(): void
    {
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $exception = ValidationException::withMessages(['title' => ['The title field is required.']]);
        $response  = $this->render($exception, self::precognitiveRequest('text/html'));

        self::assertTrue($response->isRedirect());
    }

    /**
     * Test that always_json renders a precognitive validation failure on an
     * HTML request as the validation body.
     *
     * @return void
     */
    public function testAlwaysJsonRendersAPrecognitiveHtmlRequestAsTheValidationBody(): void
    {
        config()->set('app.debug', true);
        config()->set('api-toolkit.exceptions.render_strategy', 'always_json');

        $exception = ValidationException::withMessages(['title' => ['The title field is required.']]);
        $response  = $this->render($exception, self::precognitiveRequest('text/html'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['title' => ['The title field is required.']], $response->getData(true)['errors']);
    }

    /**
     * Test that the json_when_expected strategy leaves a request that does not
     * expect JSON to Laravel's renderer.
     *
     * @return void
     */
    public function testJsonWhenExpectedStrategyLeavesNonJsonRequestsToLaravel(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());
        $request->headers->set('Accept', 'text/html');

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $response = $this->render(new \RuntimeException('error'), $request);

        self::assertLaravelHtmlServerError($response);
    }

    /**
     * Test that the json_when_expected strategy renders JSON when the request
     * expects JSON.
     *
     * @return void
     */
    public function testJsonWhenExpectedStrategyRendersJsonWhenJsonIsExpected(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $response = $this->render(new \RuntimeException('error'), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that the auto strategy renders JSON for non-JSON requests when debug
     * mode is disabled.
     *
     * @return void
     */
    public function testAutoStrategyRendersJsonForNonJsonRequestsWhenDebugDisabled(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());
        $request->headers->set('Accept', 'text/html');

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.render_strategy', 'auto');

        $response = $this->render(new \RuntimeException('error'), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that JSON output is compact when the pretty request parameter is not
     * provided.
     *
     * @return void
     */
    public function testJsonOutputIsCompactWithoutPrettyParameter(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $response = $this->render(new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString("\n", $content);
    }

    /**
     * Provide each pretty value and whether it asks for indented output.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function prettyValueProvider(): iterable
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'yes' => ['yes', true];
        yield '0' => ['0', false];
        yield 'false' => ['false', false];
        yield 'off' => ['off', false];
        yield 'no' => ['no', false];
    }

    /**
     * Test that the pretty parameter is read as a boolean, as the pretty-print
     * middleware reads it.
     *
     * @param  string  $value
     * @param  bool  $indented
     * @return void
     */
    #[DataProvider('prettyValueProvider')]
    public function testPrettyParameterIsReadAsABoolean(string $value, bool $indented): void
    {
        config()->set('app.debug', false);

        $content = $this->renderToJson(new NotFoundHttpException, self::jsonRequest(['pretty' => $value]))->getContent();

        self::assertIsString($content);
        self::assertSame($indented, str_contains($content, "\n    "));
    }

    /**
     * Provide the query of a compact and of a pretty-printed request.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function prettyQueryProvider(): iterable
    {
        yield 'compact' => [[]];
        yield 'pretty' => [['pretty' => '1']];
    }

    /**
     * Test that malformed UTF-8 in a validation key and message is substituted
     * rather than failing the 422.
     *
     * @param  array<string, string>  $query
     * @return void
     */
    #[DataProvider('prettyQueryProvider')]
    public function testMalformedUtf8InAValidationFailureIsSubstituted(array $query): void
    {
        config()->set('app.debug', false);

        $exception = ValidationException::withMessages(["order.n\xFFme" => "The \"n\xFFme\" key is not permitted."]);
        $response  = $this->renderToJson($exception, self::jsonRequest($query));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('order.n\ufffdme', (string) $response->getContent());
        self::assertSame(["order.n\u{FFFD}me" => ["The \"n\u{FFFD}me\" key is not permitted."]], $response->getData(true)['error']['meta']);
    }

    /**
     * Test that malformed UTF-8 in custom meta is substituted rather than
     * failing the response.
     *
     * @return void
     */
    public function testMalformedUtf8InCustomMetaIsSubstituted(): void
    {
        config()->set('app.debug', false);

        $response = $this->renderToJson(new ConflictException(["k\xFF" => "v\xFF"]), self::jsonRequest());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(["k\u{FFFD}" => "v\u{FFFD}"], $response->getData(true)['error']['meta']);
    }

    /**
     * Test that malformed UTF-8 in the message of the previous exception is
     * substituted in the debug details rather than hiding that exception.
     *
     * @return void
     */
    public function testMalformedUtf8InADebugPreviousMessageIsSubstituted(): void
    {
        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.include_debug_info', true);

        $response = $this->renderToJson(new \RuntimeException("Driver error near \xFF"), self::jsonRequest());
        $debug    = $response->getData(true)['error']['debug'];

        self::assertSame(500, $response->getStatusCode());
        self::assertSame("Driver error near \u{FFFD}", $debug['message']);
        self::assertSame(\RuntimeException::class, $debug['exception']);
    }

    /**
     * Test that malformed UTF-8 in a precognitive validation body is
     * substituted rather than failing the 422.
     *
     * @return void
     */
    public function testMalformedUtf8InAPrecognitiveValidationBodyIsSubstituted(): void
    {
        config()->set('app.debug', false);

        $exception = ValidationException::withMessages(["items.\xFF.name" => "The items.\xFF.name field is required."]);
        $response  = $this->renderToJson($exception, self::precognitiveRequest(self::ACCEPT_JSON));

        self::assertSame(
            ["items.\u{FFFD}.name" => ["The items.\u{FFFD}.name field is required."]],
            $response->getData(true)['errors'],
        );
    }

    /**
     * Test that an absent or empty meta value is omitted from the error
     * payload.
     *
     * @return void
     */
    public function testEmptyMetaIsOmittedFromErrorPayload(): void
    {
        config()->set('app.debug', false);

        $error = $this->renderToJson(new ConflictException([]), self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        self::assertArrayNotHasKey('meta', $error);

        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $response = $this->render(new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertArrayNotHasKey('meta', $data['error']);
    }

    /**
     * Test that the include_debug_info config takes precedence over a disabled
     * debug mode.
     *
     * @return void
     */
    public function testIncludeDebugInfoConfigTakesPrecedenceOverDisabledDebugMode(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.include_debug_info', true);

        $response = $this->render(new \RuntimeException(self::GENERIC_ERROR_MESSAGE), $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertArrayHasKey('debug', $data['error']);
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $data['error']['debug']['message']);
        self::assertArrayHasKey('trace', $data['error']['debug']);
    }

    /**
     * Test that the include_debug_info config takes precedence over an enabled
     * debug mode.
     *
     * @return void
     */
    public function testIncludeDebugInfoConfigTakesPrecedenceOverEnabledDebugMode(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', true);
        config()->set('api-toolkit.exceptions.include_debug_info', false);

        $response = $this->render(new \RuntimeException(self::GENERIC_ERROR_MESSAGE), $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertArrayNotHasKey('debug', $data['error']);
        self::assertArrayNotHasKey('meta', $data['error']);
    }

    /**
     * Test that custom meta is returned untouched when debug mode is enabled
     * but the exception has no previous exception.
     *
     * @return void
     */
    public function testCustomMetaIsReturnedWhenDebugEnabledWithoutPreviousException(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', true);

        $exception = new BadRequestException(['custom' => 'value']);

        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertSame(['custom' => 'value'], $data['error']['meta']);
        self::assertArrayNotHasKey('debug', $data['error']);
    }

    /**
     * Test that the debug details render beside the custom meta, which they
     * leave untouched.
     *
     * @return void
     */
    public function testDebugDetailsRenderBesideTheCustomMeta(): void
    {
        config()->set('app.debug', true);

        $previous = new \RuntimeException(self::GENERIC_ERROR_MESSAGE);
        $error    = $this->renderToJson(new BadRequestException(['custom' => 'value'], null, $previous), self::jsonRequest())->getData(true)['error'];

        self::assertSame(['custom' => 'value'], $error['meta']);
        self::assertIsArray($error['debug']);
        self::assertSame(['message', 'exception', 'file', 'line', 'trace'], array_keys($error['debug']));
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $error['debug']['message']);
        self::assertSame(\RuntimeException::class, $error['debug']['exception']);
        self::assertSame($previous->getFile(), $error['debug']['file']);
        self::assertSame($previous->getLine(), $error['debug']['line']);
    }

    /**
     * Test that a custom meta key named like a debug detail survives debug
     * mode.
     *
     * @return void
     */
    public function testCustomMetaKeyNamedLikeADebugDetailSurvivesDebugMode(): void
    {
        config()->set('app.debug', true);

        $exception = new BadRequestException(['message' => 'Choose another slot'], null, new \RuntimeException(self::GENERIC_ERROR_MESSAGE));
        $error     = $this->renderToJson($exception, self::jsonRequest())->getData(true)['error'];

        self::assertSame(['message' => 'Choose another slot'], $error['meta']);
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $error['debug']['message']);
    }

    /**
     * Test that a code without a detail translation renders an empty detail.
     *
     * @return void
     */
    public function testCodeWithoutADetailTranslationRendersAnEmptyDetail(): void
    {
        config()->set('app.debug', false);

        $error = $this->renderToJson(new PaymentRequiredException, self::jsonRequest())->getData(true)['error'];

        self::assertSame(['status' => 402, 'code' => 20003, 'title' => 'Payment Required', 'detail' => ''], $error);
    }

    /**
     * Test that a detail and a title of '0' are rendered.
     *
     * @return void
     */
    public function testZeroDetailAndTitleAreRendered(): void
    {
        config()->set('app.debug', false);

        Lang::addLines(['exceptions.10108.title' => '0'], 'en', 'api-toolkit');

        $error = $this->renderToJson(new ConflictException(detail: '0'), self::jsonRequest())->getData(true)['error'];

        self::assertSame(['status' => 409, 'code' => 10108, 'title' => '0', 'detail' => '0'], $error);
    }

    /**
     * Provide policy denials with the status, code, detail and meta each
     * renders.
     *
     * @return iterable<string, array{\Throwable, int, int, string, array<string, mixed>|null}>
     */
    public static function denialProvider(): iterable
    {
        yield 'message and string code' => [new AuthorizationException(self::DENIAL, 'TRIAL_EXPIRED'), 403, 10102, self::DENIAL, ['reason' => 'TRIAL_EXPIRED']];
        yield 'int code' => [new AuthorizationException(self::DENIAL, 42), 403, 10102, self::DENIAL, ['reason' => 42]];
        yield 'backed enum code' => [new AuthorizationException(self::DENIAL, UserStatus::BANNED), 403, 10102, self::DENIAL, ['reason' => 'banned']];
        yield 'backed enum code of zero' => [new AuthorizationException(self::DENIAL, Magnitude::ZERO), 403, 10102, self::DENIAL, ['reason' => 0]];
        yield 'array code' => [new AuthorizationException(self::DENIAL, ['TRIAL_EXPIRED']), 403, 10102, self::DENIAL, null];
        yield 'no code' => [new AuthorizationException(self::DENIAL), 403, 10102, self::DENIAL, null];
        yield 'default message' => [new AuthorizationException, 403, 10102, self::FORBIDDEN_DETAIL, null];
        yield 'default message and a code' => [new AuthorizationException(null, 'TRIAL_EXPIRED'), 403, 10102, self::FORBIDDEN_DETAIL, ['reason' => 'TRIAL_EXPIRED']];
        yield 'empty message' => [new AuthorizationException(''), 403, 10102, self::FORBIDDEN_DETAIL, null];
        yield 'denial deeper in the chain' => [
            new AccessDeniedHttpException('', new \RuntimeException('', 0, new AuthorizationException(self::DENIAL))), 403, 10102, self::FORBIDDEN_DETAIL, null,
        ];
        yield 'status standing for a key' => [(new AuthorizationException(self::DENIAL, 'HIDDEN'))->withStatus(404), 404, 10103, self::DENIAL, ['reason' => 'HIDDEN']];
        yield 'token mismatch status' => [(new AuthorizationException(self::DENIAL, 'STALE'))->withStatus(419), 419, 10105, self::DENIAL, ['reason' => 'STALE']];
        yield 'generic client status' => [(new AuthorizationException(self::DENIAL, 'SHIPPED'))->withStatus(409), 409, 10113, self::DENIAL, ['reason' => 'SHIPPED']];
        yield 'server status standing for a key' => [(new AuthorizationException(self::DENIAL, 'DOWN'))->withStatus(503), 503, 10112, self::UNAVAILABLE_DETAIL, null];
        yield 'generic server status' => [(new AuthorizationException(self::DENIAL, 'DOWN'))->withStatus(500), 500, 10113, 'The request could not be completed', null];
        yield 'unknown status' => [(new AuthorizationException(self::DENIAL, 'DOWN'))->withStatus(499), 500, 10001, 'Oh no! Something has gone wrong!', null];
    }

    /**
     * Test that a policy denial rendered as a client error carries its message
     * as the detail and its code as the reason.
     *
     * @param  \Throwable  $denial
     * @param  int  $status
     * @param  int  $code
     * @param  string  $detail
     * @param  array<string, mixed>|null  $meta
     * @return void
     */
    #[DataProvider('denialProvider')]
    public function testDenialRendersItsMessageAndCodeAsAClientError(\Throwable $denial, int $status, int $code, string $detail, ?array $meta): void
    {
        config()->set('app.debug', false);

        $error = $this->renderToJson($denial, self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        $expected = ['status' => $status, 'code' => $code, 'detail' => $detail, 'meta' => $meta];

        self::assertSame(array_filter($expected, static fn (mixed $value): bool => $value !== null), Arr::except($error, 'title'));
    }

    /**
     * Test that a denial's reason is added beside the meta the exception
     * carries.
     *
     * @return void
     */
    public function testDenialReasonIsAddedBesideTheExceptionMeta(): void
    {
        config()->set('app.debug', false);

        $exception = new class (new AuthorizationException(self::DENIAL, 'SHIPPED')) extends SymfonyHttpException {
            /**
             * Create a conflict raised from the given denial.
             *
             * @param  \Illuminate\Auth\Access\AuthorizationException  $denial
             */
            public function __construct(AuthorizationException $denial)
            {
                parent::__construct(409, '', $denial);
            }

            /**
             * Get the errors the exception carries.
             *
             * @return array<string, list<string>>
             */
            public function errors(): array
            {
                return ['order' => ['The order has shipped.']];
            }
        };

        $error = $this->renderToJson($exception, self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        self::assertSame(['order' => ['The order has shipped.'], 'reason' => 'SHIPPED'], $error['meta']);
    }

    /**
     * Provide configured targets of a denial, with whether each carries it.
     *
     * @return iterable<string, array{class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>, int, bool}>
     */
    public static function configuredDenialTargetProvider(): iterable
    {
        yield 'client error' => [PaymentRequiredException::class, 402, true];

        yield 'server error' => [ServiceUnavailableException::class, 503, false];
    }

    /**
     * Test that a denial mapped onto a configured target is carried only when
     * the target renders as a client error.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $target
     * @param  int  $status
     * @param  bool  $carried
     * @return void
     */
    #[DataProvider('configuredDenialTargetProvider')]
    public function testConfiguredDenialTargetCarriesTheDenialOnlyAsAClientError(string $target, int $status, bool $carried): void
    {
        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.map', [AccessDeniedHttpException::class => $target]);

        $error = $this->renderToJson(new AuthorizationException(self::DENIAL, 'TRIAL_EXPIRED'), self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        self::assertSame($status, $error['status']);
        self::assertSame($carried ? self::DENIAL : (new $target)->getCustomDetail(), $error['detail']);
        self::assertSame($carried ? ['reason' => 'TRIAL_EXPIRED'] : null, $error['meta'] ?? null);
    }

    /**
     * Test that a denial the application wrapped in its own exception keeps the
     * configured target's catalogue detail.
     *
     * @return void
     */
    public function testApplicationWrappedDenialKeepsTheCatalogueDetail(): void
    {
        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.map', [\RuntimeException::class => ConflictException::class]);

        $error = $this->renderToJson(new \RuntimeException('', 0, new AuthorizationException(self::DENIAL, 'SHIPPED')), self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        self::assertSame(['status' => 409, 'code' => 10108, 'detail' => (new ConflictException)->getCustomDetail()], Arr::except($error, 'title'));
    }

    /**
     * Test that a denial in debug mode renders its reason in the meta and its
     * debug details beside it.
     *
     * @return void
     */
    public function testDenialInDebugModeRendersItsReasonBesideTheDebugDetails(): void
    {
        config()->set('app.debug', true);

        $error = $this->renderToJson(new AuthorizationException(self::DENIAL, 'TRIAL_EXPIRED'), self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        self::assertSame(['reason' => 'TRIAL_EXPIRED'], $error['meta']);
        self::assertSame(self::DENIAL, $error['debug']['message']);
        self::assertSame(AccessDeniedHttpException::class, $error['debug']['exception']);
    }

    /**
     * Provide validation failure statuses with the status and code each
     * renders.
     *
     * @return iterable<string, array{int|null, int, int}>
     */
    public static function validationStatusProvider(): iterable
    {
        yield 'default' => [null, 422, 10106];
        yield '422' => [422, 422, 10106];
        yield '400' => [400, 400, 10113];
        yield '429' => [429, 429, 10113];
        yield 'success status' => [200, 422, 10106];
        yield 'server status' => [503, 422, 10106];
        yield 'unknown status' => [499, 422, 10106];
    }

    /**
     * Test that a validation failure keeps a client-error status other than 422
     * as a generic HTTP error, and renders any other as invalid input.
     *
     * @param  int|null  $status
     * @param  int  $expectedStatus
     * @param  int  $code
     * @return void
     */
    #[DataProvider('validationStatusProvider')]
    public function testValidationFailureKeepsAClientErrorStatus(?int $status, int $expectedStatus, int $code): void
    {
        config()->set('app.debug', false);

        $exception = ValidationException::withMessages(['email' => 'The email is taken.']);

        if ($status !== null) {
            $exception->status($status);
        }

        $error = $this->renderToJson($exception, self::jsonRequest())->getData(true)['error'];

        self::assertIsArray($error);
        self::assertSame($expectedStatus, $error['status']);
        self::assertSame($code, $error['code']);
        self::assertSame(['email' => ['The email is taken.']], $error['meta']);
    }

    /**
     * Test that a configured validation failure target keeps its own status.
     *
     * @return void
     */
    public function testConfiguredValidationTargetKeepsItsOwnStatus(): void
    {
        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.map', [ValidationException::class => ConflictException::class]);

        $response = $this->renderToJson(ValidationException::withMessages(['email' => 'The email is taken.'])->status(429), self::jsonRequest());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(10108, $response->getData(true)['error']['code']);
    }

    /**
     * Test that a meta keyed by sequential integers renders as a JSON object.
     *
     * @return void
     */
    public function testMetaKeyedBySequentialIntegersRendersAsAnObject(): void
    {
        config()->set('app.debug', false);

        $exception = ValidationException::withMessages(['0' => 'The 0 field must be an integer.', '1' => 'The 1 field must be an integer.']);
        $content   = $this->renderToJson($exception, self::jsonRequest())->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('"meta":{"0":["The 0 field must be an integer."],"1":["The 1 field must be an integer."]}', $content);
    }

    /**
     * Test that debug trace frames exclude call arguments.
     *
     * @return void
     */
    public function testDebugTraceFramesExcludeCallArguments(): void
    {
        $original = ini_set('zend.exception_ignore_args', '0');

        try {
            $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
            $request->headers->set('Accept', self::ACCEPT_JSON);

            config()->set('app.debug', true);

            $previous = call_user_func(
                static fn (string $secret): \RuntimeException => new \RuntimeException(self::GENERIC_ERROR_MESSAGE),
                'sensitive-value',
            );

            $framesWithArgs = array_filter(
                $previous->getTrace(),
                static fn (array $frame): bool => array_key_exists('args', $frame),
            );

            self::assertNotEmpty($framesWithArgs);

            $response = $this->render($previous, $request);

            self::assertInstanceOf(JsonResponse::class, $response);

            $data = $response->getData(true);

            self::assertIsArray($data);
            self::assertArrayHasKey('error', $data);
            self::assertArrayHasKey('debug', $data['error']);
            self::assertArrayHasKey('trace', $data['error']['debug']);
            self::assertIsArray($data['error']['debug']['trace']);
            self::assertNotEmpty($data['error']['debug']['trace']);

            // One set assertion: the frame count varies with stack depth
            $renderedFramesWithArgs = array_filter(
                $data['error']['debug']['trace'],
                static fn (mixed $frame): bool => is_array($frame) && array_key_exists('args', $frame),
            );

            self::assertSame([], $renderedFramesWithArgs);
        } finally {
            if ($original !== false) {
                ini_set('zend.exception_ignore_args', $original);
            }
        }
    }

    /**
     * Provide the position of an extra reportable relative to handles().
     *
     * @return iterable<string, array{bool}>
     */
    public static function registrationOrderProvider(): iterable
    {
        yield 'reportable registered before handles()' => [true];

        yield 'reportable registered after handles()' => [false];
    }

    /**
     * Test that a handled API exception is not reported whatever the order in
     * which another reportable was registered.
     *
     * @param  bool  $reportableFirst
     * @return void
     */
    #[DataProvider('registrationOrderProvider')]
    public function testHandledExceptionIsNotReportedWhateverTheRegistrationOrder(bool $reportableFirst): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        $captured = [];
        $capture  = static function (\Throwable $exception) use (&$captured): bool {
            $captured[] = $exception;

            return false;
        };

        if ($reportableFirst) {
            $exceptions->report($capture);
        }

        ApiExceptionHandler::handles($exceptions);

        if (!$reportableFirst) {
            $exceptions->report($capture);
        }

        $runtime = new \RuntimeException(self::GENERIC_ERROR_MESSAGE);

        $handler->report(new ConflictException);
        $handler->report($runtime);

        self::assertSame([$runtime], $captured);
    }

    /**
     * Test that a deliberately thrown 5xx API exception is not reported.
     *
     * @return void
     */
    public function testMaintenanceModeExceptionIsNotReported(): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        self::assertTrue($handler->shouldReport(new MaintenanceModeException));

        ApiExceptionHandler::handles($exceptions);

        self::assertFalse($handler->shouldReport(new MaintenanceModeException));
    }

    /**
     * Test that a hand-thrown unhandled exception is still reported as the same
     * instance.
     *
     * @return void
     */
    public function testHandThrownUnhandledExceptionIsReported(): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        $captured = [];

        ApiExceptionHandler::handles($exceptions);

        $exceptions->report(static function (\Throwable $exception) use (&$captured): bool {
            $captured[] = $exception;

            return false;
        });

        $unhandled = new UnhandledException;

        $handler->report($unhandled);

        self::assertSame([$unhandled], $captured);
    }

    /**
     * Test that Laravel's validation unauthorized exception is not reported
     * once the toolkit handler is registered.
     *
     * @return void
     */
    public function testLaravelUnauthorizedExceptionIsNotReported(): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        self::assertTrue($handler->shouldReport(new LaravelUnauthorizedException));

        ApiExceptionHandler::handles($exceptions);

        self::assertFalse($handler->shouldReport(new LaravelUnauthorizedException));
    }

    /**
     * Test that a non-API exception is rendered through the registered handler
     * as the generic 500 envelope without leaking its internal message.
     *
     * @return void
     */
    public function testNonApiExceptionRendersAsGenericServerError(): void
    {
        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.include_debug_info', false);

        [$handler, $exceptions] = $this->exceptionHandler();

        ApiExceptionHandler::handles($exceptions);

        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        $response = $handler->render($request, new \RuntimeException('internal detail'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame([
            'error' => [
                'status' => 500,
                'code'   => UnhandledException::getInternalErrorCode(),
                'title'  => 'Unknown Error',
                'detail' => 'Oh no! Something has gone wrong!',
            ],
        ], $response->getData(true));
        self::assertStringNotContainsString('internal detail', (string) $response->getContent());
    }

    /**
     * Test that a configured mapping renders as the configured exception, with
     * the headers of the exception it replaces.
     *
     * @return void
     */
    public function testConfiguredMappingRendersTheConfiguredException(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.map', [TooManyRequestsHttpException::class => ConflictException::class]);

        $response = $this->render(new TooManyRequestsHttpException(30), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(10108, $data['error']['code']);
    }

    /**
     * Test that an HTTP exception carrying only a status follows a configured
     * mapping of the key its status stands for.
     *
     * @return void
     */
    public function testStatusOnlyHttpExceptionFollowsTheConfiguredKeyItStandsFor(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class]);

        $response = $this->render(new SymfonyHttpException(404), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(410, $response->getStatusCode());
    }

    /**
     * Provide keys naming the HTTP exception itself.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function httpExceptionKeyProvider(): iterable
    {
        yield 'concrete class' => [SymfonyHttpException::class];

        yield 'interface' => [SymfonyHttpExceptionInterface::class];
    }

    /**
     * Test that a key naming the HTTP exception itself takes precedence over
     * the key its status stands for.
     *
     * @param  class-string  $key
     * @return void
     */
    #[DataProvider('httpExceptionKeyProvider')]
    public function testKeyNamingTheHttpExceptionTakesPrecedenceOverItsStatus(string $key): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.map', [$key => GoneException::class]);

        $response = $this->render(new SymfonyHttpException(404), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(410, $response->getStatusCode());
    }

    /**
     * Test that an invalid map is reported once and leaves the rendering to
     * Laravel.
     *
     * @return void
     */
    public function testInvalidMapIsReportedAndLeavesRenderingToLaravel(): void
    {
        config()->set('app.debug', false);

        $this->registeredHandler();

        ExceptionsFacade::fake();

        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('api-toolkit.exceptions.map', [\stdClass::class => ConflictException::class]);

        self::assertLaravelJsonServerError($this->render(new \RuntimeException, $request));

        ExceptionsFacade::assertReportedCount(1);
        ExceptionsFacade::assertReported(static fn (InvalidConfigurationException $exception): bool => $exception->getMessage()
            === 'The api-toolkit.exceptions.map key [stdClass] must be an interface or a Throwable class, and not an ApiException.');
    }

    /**
     * Test that rendering an invalid configuration failure leaves it to Laravel
     * without reading the configuration or reporting it again.
     *
     * @return void
     */
    public function testInvalidConfigurationFailureIsLeftToLaravelUnreported(): void
    {
        config()->set('app.debug', false);

        $this->registeredHandler();

        ExceptionsFacade::fake();

        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('api-toolkit.exceptions.render_strategy', 'bogus');
        config()->set('api-toolkit.exceptions.map', [\stdClass::class => ConflictException::class]);

        self::assertLaravelJsonServerError($this->render(InvalidConfigurationException::because('Invalid.'), $request));

        ExceptionsFacade::assertNothingReported();
    }

    /**
     * Provide the invalid rendering settings and the message each reports.
     *
     * @return iterable<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function invalidRenderingSettingProvider(): iterable
    {
        yield 'render strategy typo' => [
            'render_strategy',
            'json-when-expected',
            'The api-toolkit.exceptions.render_strategy setting must be one of always_json, json_when_expected, auto; [\'json-when-expected\'] given.',
        ];

        yield 'debug info spelling' => [
            'include_debug_info',
            'maybe',
            'The api-toolkit.exceptions.include_debug_info setting must be a boolean, or one of 1, 0, true, false, on, off, yes, no, or null; [\'maybe\'] given.',
        ];
    }

    /**
     * Test that an invalid rendering setting is reported once and leaves the
     * rendering, of an ApiException too, to Laravel, which keeps its status.
     *
     * @param  string  $setting
     * @param  mixed  $value
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidRenderingSettingProvider')]
    public function testInvalidRenderingSettingIsReportedAndLeavesRenderingToLaravel(string $setting, mixed $value, string $message): void
    {
        config()->set('app.debug', false);

        $this->registeredHandler();

        ExceptionsFacade::fake();

        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('api-toolkit.exceptions.' . $setting, $value);

        $exception = new ConflictException;
        $response  = $this->render($exception, $request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['message' => $exception->getMessage()], json_decode((string) $response->getContent(), true));

        ExceptionsFacade::assertReportedCount(1);
        ExceptionsFacade::assertReported(static fn (InvalidConfigurationException $exception): bool => $exception->getMessage() === $message);
    }

    /**
     * Test that a configured mapping leaves the reporting decision unchanged.
     *
     * @return void
     */
    public function testConfiguredMappingLeavesReportingUnchanged(): void
    {
        config()->set('api-toolkit.exceptions.map', [\RuntimeException::class => ConflictException::class]);

        [$handler, $exceptions] = $this->exceptionHandler();

        ApiExceptionHandler::handles($exceptions);

        self::assertTrue($handler->shouldReport(new \RuntimeException));
    }

    /**
     * Test that the reporting samples cover every entry of the exception map.
     *
     * @return void
     */
    public function testReportingSamplesCoverEveryExceptionMapEntry(): void
    {
        $map = (new \ReflectionClassConstant(ExceptionMap::class, 'DEFAULTS'))->getValue();

        self::assertIsArray($map);

        $mapped  = array_keys($map);
        $sampled = array_column(iterator_to_array(self::reportingSampleProvider()), 0);

        sort($mapped);
        sort($sampled);

        self::assertSame($mapped, $sampled);
    }

    /**
     * Test that the prepared samples cover every type Laravel converts before
     * rendering.
     *
     * @return void
     */
    public function testPreparedSamplesCoverEveryPreparedType(): void
    {
        $prepared = (new \ReflectionClassConstant(ExceptionMap::class, 'PREPARED'))->getValue();

        self::assertIsArray($prepared);

        $sampled = array_column(iterator_to_array(self::preparedSampleProvider()), 0);

        sort($prepared);
        sort($sampled);

        self::assertSame($prepared, $sampled);
    }

    /**
     * Provide statuses carried by an HTTP exception that does not extend the
     * Symfony base class, with whether the toolkit reports it.
     *
     * @return iterable<string, array{int, int, bool}>
     */
    public static function httpExceptionImplementorProvider(): iterable
    {
        yield 'client error' => [409, 409, false];

        yield 'session token mismatch' => [419, 419, false];

        yield 'server error' => [503, 503, true];

        yield 'unknown status' => [499, 500, true];
    }

    /**
     * Test that an HTTP exception implementing the Symfony interface without
     * extending its base class is reported only when it renders as a 5xx.
     *
     * @param  int  $status
     * @param  int  $expectedStatus
     * @param  bool  $expectedReported
     * @return void
     */
    #[DataProvider('httpExceptionImplementorProvider')]
    public function testHttpExceptionImplementorIsReportedOnlyAsServerError(int $status, int $expectedStatus, bool $expectedReported): void
    {
        $exception = self::httpExceptionImplementor($status);

        self::assertTrue($this->exceptionHandler()[0]->shouldReport($exception));

        $handler = $this->registeredHandler();

        self::assertSame($expectedStatus, $handler->render(self::jsonRequest(), $exception)->getStatusCode());
        self::assertSame($expectedReported, $handler->shouldReport($exception));
    }

    /**
     * Provide a sample of every source type in the handler's exception map,
     * with the status the map resolves it to.
     *
     * @return iterable<string, array{class-string, \Closure(): \Throwable, int}>
     */
    public static function reportingSampleProvider(): iterable
    {
        yield 'not found http' => [
            NotFoundHttpException::class,
            static fn (): \Throwable => new NotFoundHttpException,
            404,
        ];

        yield 'method not allowed' => [
            MethodNotAllowedHttpException::class,
            static fn (): \Throwable => new MethodNotAllowedHttpException(['GET']),
            405,
        ];

        yield 'bad request http' => [
            BadRequestHttpException::class,
            static fn (): \Throwable => new BadRequestHttpException,
            400,
        ];

        yield 'laravel unauthorized' => [
            LaravelUnauthorizedException::class,
            static fn (): \Throwable => new LaravelUnauthorizedException,
            403,
        ];

        yield 'access denied http' => [
            AccessDeniedHttpException::class,
            static fn (): \Throwable => new AccessDeniedHttpException,
            403,
        ];

        yield 'authentication' => [
            AuthenticationException::class,
            static fn (): \Throwable => new AuthenticationException,
            401,
        ];

        yield 'validation' => [
            ValidationException::class,
            static fn (): \Throwable => ValidationException::withMessages(['email' => 'invalid']),
            422,
        ];

        yield 'too many requests http' => [
            TooManyRequestsHttpException::class,
            static fn (): \Throwable => new TooManyRequestsHttpException,
            429,
        ];

        yield 'lock unavailable' => [
            LockUnavailableException::class,
            static fn (): \Throwable => new LockUnavailableException,
            429,
        ];

        yield 'service unavailable http' => [
            ServiceUnavailableHttpException::class,
            static fn (): \Throwable => new ServiceUnavailableHttpException,
            503,
        ];

        yield 'post too large' => [
            PostTooLargeException::class,
            static fn (): \Throwable => new PostTooLargeException,
            413,
        ];

        yield 'precondition failed http' => [
            PreconditionFailedHttpException::class,
            static fn (): \Throwable => new PreconditionFailedHttpException,
            412,
        ];

        yield 'precondition required http' => [
            PreconditionRequiredHttpException::class,
            static fn (): \Throwable => new PreconditionRequiredHttpException,
            428,
        ];

        yield 'symfony http exception' => [
            SymfonyHttpExceptionInterface::class,
            static fn (): \Throwable => self::httpExceptionImplementor(409),
            409,
        ];
    }

    /**
     * Test that every exception map key reaches the render callback as thrown,
     * and is not reported once the toolkit handler is registered when it maps
     * to a client error.
     *
     * @param  class-string  $source
     * @param  \Closure(): \Throwable  $factory
     * @param  int  $expectedStatus
     * @return void
     */
    #[DataProvider('reportingSampleProvider')]
    public function testMapKeyArrivesAsThrownAndClientErrorsAreNotReported(string $source, \Closure $factory, int $expectedStatus): void
    {
        $sample = $factory();

        self::assertInstanceOf($source, $sample);

        [$arrived, $response] = $this->renderObservingArrival($sample);

        self::assertSame($sample, $arrived);
        self::assertSame($expectedStatus, $response->getStatusCode());

        if ($expectedStatus >= 500) {
            return;
        }

        self::assertFalse($this->registeredHandler()->shouldReport($sample));
    }

    /**
     * Provide a sample of every type Laravel converts before rendering.
     *
     * @return iterable<string, array{class-string, \Closure(): \Throwable}>
     */
    public static function preparedSampleProvider(): iterable
    {
        yield 'backed enum case not found' => [
            BackedEnumCaseNotFoundException::class,
            static fn (): \Throwable => new BackedEnumCaseNotFoundException('App\Enums\Status', 'unknown'),
        ];

        yield 'model not found' => [
            ModelNotFoundException::class,
            static fn (): \Throwable => new ModelNotFoundException,
        ];

        yield 'authorization' => [
            AuthorizationException::class,
            static fn (): \Throwable => new AuthorizationException,
        ];

        yield 'origin mismatch' => [
            OriginMismatchException::class,
            static fn (): \Throwable => new OriginMismatchException,
        ];

        yield 'token mismatch' => [
            LaravelTokenMismatchException::class,
            static fn (): \Throwable => new LaravelTokenMismatchException,
        ];

        yield 'request exception' => [
            RequestExceptionInterface::class,
            static fn (): \Throwable => new SuspiciousOperationException,
        ];

        yield 'record not found' => [
            RecordNotFoundException::class,
            static fn (): \Throwable => new RecordNotFoundException,
        ];

        yield 'records not found' => [
            RecordsNotFoundException::class,
            static fn (): \Throwable => new RecordsNotFoundException,
        ];
    }

    /**
     * Test that every type Laravel converts reaches the render callback as
     * another exception, and is not reported.
     *
     * @param  class-string  $source
     * @param  \Closure(): \Throwable  $factory
     * @return void
     */
    #[DataProvider('preparedSampleProvider')]
    public function testPreparedTypeIsConvertedAndNotReported(string $source, \Closure $factory): void
    {
        $sample = $factory();

        self::assertInstanceOf($source, $sample);

        [$arrived] = $this->renderObservingArrival($sample);

        self::assertNotSame($sample, $arrived);
        self::assertFalse($this->registeredHandler()->shouldReport($sample));
    }

    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [ApiServiceProvider::class];
    }

    /**
     * Define the test environment.
     *
     * Loads the package's exception translations so rendered errors carry a
     * detail.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Translation\Translator $translator */
        $translator = $app['translator'];

        $translator->addNamespace('api-toolkit', __DIR__ . '/../../../resources/lang');
    }

    /**
     * Resolve the application's real exception handler and its configuration
     * wrapper.
     *
     * @return array{\Illuminate\Foundation\Exceptions\Handler, \Illuminate\Foundation\Configuration\Exceptions}
     */
    private function exceptionHandler(): array
    {
        assert($this->app !== null);

        $handler = $this->app->make(ExceptionHandlerContract::class);

        assert($handler instanceof Handler);

        return [$handler, new Exceptions($handler)];
    }

    /**
     * Render the exception through the application's exception handler,
     * returning the exception its render callbacks received and the response.
     *
     * @param  \Throwable  $exception
     * @return array{\Throwable|null, \Symfony\Component\HttpFoundation\Response}
     */
    private function renderObservingArrival(\Throwable $exception): array
    {
        [, $exceptions] = $this->exceptionHandler();

        $arrived = null;

        $exceptions->render(static function (\Throwable $received) use (&$arrived): void {
            $arrived = $received;
        });

        $response = $this->render($exception, self::jsonRequest());

        return [$arrived, $response];
    }

    /**
     * Render the exception through the application's exception handler, with
     * the toolkit registered against it.
     *
     * @param  \Throwable  $exception
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function render(\Throwable $exception, Request $request): Response
    {
        return $this->registeredHandler()->render($request, $exception);
    }

    /**
     * Resolve the application's exception handler, registering the toolkit
     * against it once.
     *
     * @return \Illuminate\Foundation\Exceptions\Handler
     */
    private function registeredHandler(): Handler
    {
        if ($this->handler === null) {

            [$this->handler, $exceptions] = $this->exceptionHandler();

            ApiExceptionHandler::handles($exceptions);
        }

        return $this->handler;
    }

    /**
     * Assert the response is Laravel's own JSON server error, not the toolkit
     * envelope.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    private static function assertLaravelJsonServerError(Response $response): void
    {
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['message' => 'Server Error'], json_decode((string) $response->getContent(), true));
    }

    /**
     * Assert the response is Laravel's own HTML server error page.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    private static function assertLaravelHtmlServerError(Response $response): void
    {
        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    /**
     * Render the exception through the handler, asserting a JSON response.
     *
     * @param  \Throwable  $exception
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function renderToJson(\Throwable $exception, Request $request): JsonResponse
    {
        $response = $this->render($exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /**
     * Build a request expecting JSON with the given query.
     *
     * @param  array<string, string>  $query
     * @return \Illuminate\Http\Request
     */
    private static function jsonRequest(array $query = []): Request
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb(), $query);

        $request->headers->set('Accept', self::ACCEPT_JSON);

        return $request;
    }

    /**
     * Build a request that the Precognition middleware has marked precognitive.
     *
     * @param  string  $accept
     * @return \Illuminate\Http\Request
     */
    private static function precognitiveRequest(string $accept): Request
    {
        $request = Request::create(self::API_PATH, HttpMethod::POST->getVerb());

        $request->headers->set('Accept', $accept);
        $request->attributes->set('precognitive', true);

        return $request;
    }

    /**
     * Build an HTTP exception that implements the Symfony interface without
     * extending its base class, so Laravel's own don't-report list misses it.
     *
     * @param  int  $status
     * @return \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
     */
    private static function httpExceptionImplementor(int $status): SymfonyHttpExceptionInterface
    {
        return new class ($status) extends \RuntimeException implements SymfonyHttpExceptionInterface {
            /**
             * Create a new HTTP exception with the given status.
             *
             * @param  int  $status
             */
            public function __construct(

                /** The HTTP status code */
                private readonly int $status,
            ) {
                parent::__construct();
            }

            /**
             * Get the HTTP status code.
             *
             * @return int
             */
            #[\Override]
            public function getStatusCode(): int
            {
                return $this->status;
            }

            /**
             * Get the response headers.
             *
             * @return array<string, string>
             */
            #[\Override]
            public function getHeaders(): array
            {
                return [];
            }
        };
    }
}
