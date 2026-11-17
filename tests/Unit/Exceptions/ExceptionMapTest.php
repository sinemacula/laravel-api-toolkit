<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\EntryNotFoundException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerExceptionInterface;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\HttpException;
use SineMacula\ApiToolkit\Exceptions\InvalidExceptionMapException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\LockedException;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Exceptions\NotAllowedException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\PayloadTooLargeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Exceptions\UnauthenticatedException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Fixtures\OpenApi\CodelessApiException;
use Tests\Fixtures\OpenApi\RateLimitedException;
use Tests\Fixtures\OpenApi\StatuslessApiException;
use Tests\TestCase;

/**
 * Tests for the exception map.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExceptionMap::class)]
final class ExceptionMapTest extends TestCase
{
    /** @var string A subtype of the built-in NotFoundHttpException key */
    private const string MISSING_WIDGET = 'Tests\Fixtures\Exceptions\ExceptionMap\MissingWidgetHttpException';

    /** @var string A subtype of MISSING_WIDGET */
    private const string MISSING_GADGET = 'Tests\Fixtures\Exceptions\ExceptionMap\MissingGadgetHttpException';

    /** @var string An application subtype of AuthorizationException */
    private const string POLICY_DENIED = 'Tests\Fixtures\Exceptions\ExceptionMap\PolicyDeniedException';

    /** @var string A class whose autoloading raises an error */
    private const string EXPLODING = 'App\Exceptions\ExplodingException';

    /** @var string The namespace of the stubbed target fixtures */
    private const string FIXTURES = 'Tests\Fixtures\Exceptions\ExceptionMap\\';

    /** @var string The message for a setting that is not an array */
    private const string SETTING_MESSAGE = 'The api-toolkit.exceptions.map setting must be an array of ApiException classes keyed by the exception class they replace.';

    /** @var string The message for a key that cannot be mapped */
    private const string KEY_MESSAGE = 'The api-toolkit.exceptions.map key [%s] must be an interface or a Throwable class, and not an ApiException.';

    /** @var string The message for a key Laravel converts before rendering */
    private const string PREPARED_MESSAGE = 'The api-toolkit.exceptions.map key [%s] never matches: Laravel converts it before rendering, so map the exception it becomes.';

    /** @var string The message for a target the handler cannot render */
    private const string TARGET_MESSAGE = 'The api-toolkit.exceptions.map target [%s] for [%s] must be an instantiable ApiException with a CODE, a 4xx or 5xx status, '
        . 'and ApiException\'s own constructor and getStatusCode().';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/Fixtures/Exceptions/ExceptionMap/ExceptionMapFixtures.stub';
    }

    /**
     * Provide a sample of every built-in key with the target it maps to.
     *
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function builtInProvider(): iterable
    {
        yield 'not found http' => [NotFoundHttpException::class, NotFoundException::class];
        yield 'method not allowed' => [MethodNotAllowedHttpException::class, NotAllowedException::class];
        yield 'bad request http' => [BadRequestHttpException::class, BadRequestException::class];
        yield 'laravel unauthorized' => [LaravelUnauthorizedException::class, ForbiddenException::class];
        yield 'access denied http' => [AccessDeniedHttpException::class, ForbiddenException::class];
        yield 'authentication' => [AuthenticationException::class, UnauthenticatedException::class];
        yield 'validation' => [ValidationException::class, InvalidInputException::class];
        yield 'too many requests http' => [TooManyRequestsHttpException::class, TooManyRequestsException::class];
        yield 'throttle requests' => [ThrottleRequestsException::class, TooManyRequestsException::class];
        yield 'lock unavailable' => [LockUnavailableException::class, TooManyRequestsException::class];
        yield 'service unavailable http' => [ServiceUnavailableHttpException::class, ServiceUnavailableException::class];
        yield 'post too large' => [PostTooLargeException::class, PayloadTooLargeException::class];
        yield 'symfony http exception' => [ConflictHttpException::class, HttpException::class];
        yield 'unmatched' => [\RuntimeException::class, UnhandledException::class];
    }

    /**
     * Test that every built-in key maps to its target, specific keys first.
     *
     * @param  class-string  $exception
     * @param  class-string  $target
     * @return void
     */
    #[DataProvider('builtInProvider')]
    public function testBuiltInsMapEachKeyToItsTarget(string $exception, string $target): void
    {
        self::assertSame($target, ExceptionMap::fromConfig()->targetFor($exception));
        self::assertSame($target, ExceptionMap::defaults()->targetFor($exception));
    }

    /**
     * Test that a configured key naming a built-in replaces it in its slot.
     *
     * @return void
     */
    public function testConfiguredBuiltInKeyReplacesTheBuiltIn(): void
    {
        foreach ([NotFoundHttpException::class, '\\' . NotFoundHttpException::class, strtolower(NotFoundHttpException::class)] as $key) {

            config()->set('api-toolkit.exceptions.map', [$key => GoneException::class]);

            $map = ExceptionMap::fromConfig();

            self::assertSame(GoneException::class, $map->targetFor(NotFoundHttpException::class));
            self::assertSame(NotAllowedException::class, $map->targetFor(MethodNotAllowedHttpException::class));
        }
    }

    /**
     * Test that a subtype of the last built-in key leaves the earlier built-ins
     * first.
     *
     * @return void
     */
    public function testSubtypeOfTheCatchAllBuiltInLeavesEarlierBuiltInsFirst(): void
    {
        config()->set('api-toolkit.exceptions.map', [SymfonyHttpException::class => ConflictException::class]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(ConflictException::class, $map->targetFor(ConflictHttpException::class));
        self::assertSame(NotFoundException::class, $map->targetFor(NotFoundHttpException::class));
        self::assertSame(TooManyRequestsException::class, $map->targetFor(TooManyRequestsHttpException::class));
    }

    /**
     * Test that a subtype of a built-in key is matched before that built-in.
     *
     * @return void
     */
    public function testSubtypeOfABuiltInKeyPrecedesIt(): void
    {
        config()->set('api-toolkit.exceptions.map', [self::MISSING_WIDGET => GoneException::class]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(GoneException::class, $map->targetFor(self::MISSING_WIDGET));
        self::assertSame(NotFoundException::class, $map->targetFor(NotFoundHttpException::class));
    }

    /**
     * Test that a subtype of a replaced built-in key precedes the replacement,
     * whatever the declared order.
     *
     * @return void
     */
    public function testSubtypeOfAReplacedBuiltInKeyPrecedesTheReplacement(): void
    {
        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class, self::MISSING_WIDGET => ConflictException::class]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(ConflictException::class, $map->targetFor(self::MISSING_WIDGET));
        self::assertSame(GoneException::class, $map->targetFor(NotFoundHttpException::class));
    }

    /**
     * Test that a key no built-in claims is matched after every built-in.
     *
     * @return void
     */
    public function testUnrelatedKeyFollowsEveryBuiltIn(): void
    {
        config()->set('api-toolkit.exceptions.map', [\RuntimeException::class => ConflictException::class, self::MISSING_WIDGET => GoneException::class]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(ConflictException::class, $map->targetFor(\RuntimeException::class));
        self::assertSame(GoneException::class, $map->targetFor(self::MISSING_WIDGET));
        self::assertSame(NotFoundException::class, $map->targetFor(NotFoundHttpException::class));
        self::assertSame(TooManyRequestsException::class, $map->targetFor(LockUnavailableException::class));
        self::assertSame(HttpException::class, $map->targetFor(ConflictHttpException::class));
    }

    /**
     * Test that a Throwable key catches what no built-in claims.
     *
     * @return void
     */
    public function testThrowableKeyCatchesEveryUnclaimedThrowable(): void
    {
        config()->set('api-toolkit.exceptions.map', [\Throwable::class => ServiceUnavailableException::class]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(ServiceUnavailableException::class, $map->targetFor(\Error::class));
        self::assertSame(ServiceUnavailableException::class, $map->targetFor(\Throwable::class));
        self::assertSame(NotFoundException::class, $map->targetFor(NotFoundHttpException::class));
    }

    /**
     * Test that a later-declared specific key still precedes an earlier broad
     * one.
     *
     * @return void
     */
    public function testSlotDecidesPrecedenceOverDeclaredOrder(): void
    {
        config()->set('api-toolkit.exceptions.map', [
            SymfonyHttpException::class => ConflictException::class,
            self::MISSING_WIDGET        => GoneException::class,
        ]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(GoneException::class, $map->targetFor(self::MISSING_WIDGET));
        self::assertSame(ConflictException::class, $map->targetFor(ConflictHttpException::class));
    }

    /**
     * Test that declared order decides between keys sharing a slot.
     *
     * @return void
     */
    public function testDeclaredOrderBreaksTiesWithinASlot(): void
    {
        config()->set('api-toolkit.exceptions.map', [self::MISSING_WIDGET => GoneException::class, self::MISSING_GADGET => LockedException::class]);

        self::assertSame(GoneException::class, ExceptionMap::fromConfig()->targetFor(self::MISSING_GADGET));

        config()->set('api-toolkit.exceptions.map', [self::MISSING_GADGET => LockedException::class, self::MISSING_WIDGET => GoneException::class]);

        self::assertSame(LockedException::class, ExceptionMap::fromConfig()->targetFor(self::MISSING_GADGET));
        self::assertSame(GoneException::class, ExceptionMap::fromConfig()->targetFor(self::MISSING_WIDGET));
    }

    /**
     * Test that an interface key matches its implementations.
     *
     * @return void
     */
    public function testInterfaceKeyMatchesItsImplementations(): void
    {
        config()->set('api-toolkit.exceptions.map', [ContainerExceptionInterface::class => ConflictException::class]);

        self::assertSame(ConflictException::class, ExceptionMap::fromConfig()->targetFor(EntryNotFoundException::class));
    }

    /**
     * Test that an interface key that is not a Throwable matches its
     * implementations.
     *
     * @return void
     */
    public function testNonThrowableInterfaceKeyMatchesItsImplementations(): void
    {
        config()->set('api-toolkit.exceptions.map', [self::FIXTURES . 'DomainFailure' => ConflictException::class]);

        self::assertSame(ConflictException::class, ExceptionMap::fromConfig()->targetFor(self::FIXTURES . 'OrderFailedException'));
    }

    /**
     * Test that the built-ins alone ignore the configured entries.
     *
     * @return void
     */
    public function testDefaultsIgnoreTheConfiguredEntries(): void
    {
        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class, 'not-a-class' => GoneException::class]);

        self::assertSame(NotFoundException::class, ExceptionMap::defaults()->targetFor(NotFoundHttpException::class));
    }

    /**
     * Test that the config is read on every build.
     *
     * @return void
     */
    public function testEveryBuildReadsTheCurrentConfig(): void
    {
        self::assertSame(NotFoundException::class, ExceptionMap::fromConfig()->targetFor(NotFoundHttpException::class));

        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class]);

        self::assertSame(GoneException::class, ExceptionMap::fromConfig()->targetFor(NotFoundHttpException::class));
    }

    /**
     * Test that an absent or empty setting configures no entry.
     *
     * @return void
     */
    public function testAbsentOrEmptySettingConfiguresNoEntry(): void
    {
        foreach ([null, []] as $setting) {

            config()->set('api-toolkit.exceptions.map', $setting);

            self::assertSame(UnhandledException::class, ExceptionMap::fromConfig()->targetFor(\RuntimeException::class));
        }
    }

    /**
     * Provide settings that are neither null nor an array.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSettingProvider(): iterable
    {
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'class name' => [GoneException::class];
    }

    /**
     * Test that a setting that is not an array fails.
     *
     * @param  mixed  $setting
     * @return void
     */
    #[DataProvider('invalidSettingProvider')]
    public function testSettingThatIsNotAnArrayFails(mixed $setting): void
    {
        config()->set('api-toolkit.exceptions.map', $setting);

        self::assertMapFails(self::SETTING_MESSAGE);
    }

    /**
     * Provide keys that cannot be mapped, with how the message names each.
     *
     * @return iterable<string, array{int|string, string}>
     */
    public static function invalidKeyProvider(): iterable
    {
        yield 'list position' => [0, '0'];
        yield 'missing class' => ['App\Exceptions\MissingException', 'App\Exceptions\MissingException'];
        yield 'non-throwable class' => [\stdClass::class, \stdClass::class];
        yield 'api exception' => [ConflictException::class, ConflictException::class];
    }

    /**
     * Test that a key that is not a non-ApiException throwable fails.
     *
     * @param  int|string  $key
     * @param  string  $named
     * @return void
     */
    #[DataProvider('invalidKeyProvider')]
    public function testKeyThatCannotBeMappedFails(int|string $key, string $named): void
    {
        config()->set('api-toolkit.exceptions.map', [$key => GoneException::class]);

        self::assertMapFails(sprintf(self::KEY_MESSAGE, $named));
    }

    /**
     * Provide keys of types Laravel converts before rendering.
     *
     * @return iterable<string, array{string}>
     */
    public static function preparedKeyProvider(): iterable
    {
        yield 'backed enum case not found' => [BackedEnumCaseNotFoundException::class];
        yield 'model not found' => [ModelNotFoundException::class];
        yield 'authorization' => [AuthorizationException::class];
        yield 'origin mismatch' => [OriginMismatchException::class];
        yield 'token mismatch' => [LaravelTokenMismatchException::class];
        yield 'request exception interface' => [RequestExceptionInterface::class];
        yield 'record not found' => [RecordNotFoundException::class];
        yield 'records not found' => [RecordsNotFoundException::class];
        yield 'request exception implementation' => [SuspiciousOperationException::class];
        yield 'application subtype' => [self::POLICY_DENIED];
        yield 'leading backslash and other casing' => ['\\' . strtolower(AuthorizationException::class)];
    }

    /**
     * Test that a key of a type Laravel converts before rendering fails, since
     * it would never match.
     *
     * @param  string  $key
     * @return void
     */
    #[DataProvider('preparedKeyProvider')]
    public function testKeyLaravelConvertsBeforeRenderingFails(string $key): void
    {
        config()->set('api-toolkit.exceptions.map', [$key => GoneException::class]);

        self::assertMapFails(sprintf(self::PREPARED_MESSAGE, $key));
    }

    /**
     * Provide each status a built-in key stands for, with the target it renders
     * as.
     *
     * @return iterable<string, array{int, class-string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'bad request' => [400, BadRequestException::class];
        yield 'unauthenticated' => [401, UnauthenticatedException::class];
        yield 'forbidden' => [403, ForbiddenException::class];
        yield 'not found' => [404, NotFoundException::class];
        yield 'method not allowed' => [405, NotAllowedException::class];
        yield 'payload too large' => [413, PayloadTooLargeException::class];
        yield 'too many requests' => [429, TooManyRequestsException::class];
        yield 'service unavailable' => [503, ServiceUnavailableException::class];
    }

    /**
     * Test that a status a built-in key stands for resolves to that key's
     * target.
     *
     * @param  int  $status
     * @param  class-string  $target
     * @return void
     */
    #[DataProvider('statusProvider')]
    public function testStatusResolvesToTheTargetOfTheKeyItStandsFor(int $status, string $target): void
    {
        self::assertSame($target, ExceptionMap::defaults()->targetForStatus($status));
    }

    /**
     * Test that a status no built-in key stands for resolves to nothing.
     *
     * @return void
     */
    public function testStatusNoBuiltInKeyStandsForResolvesToNothing(): void
    {
        foreach ([409, 419, 422, 499] as $status) {
            self::assertNull(ExceptionMap::defaults()->targetForStatus($status));
        }
    }

    /**
     * Test that a status follows a configured replacement of the key it stands
     * for.
     *
     * @return void
     */
    public function testStatusFollowsTheConfiguredKeyItStandsFor(): void
    {
        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class]);

        self::assertSame(GoneException::class, ExceptionMap::fromConfig()->targetForStatus(404));
    }

    /**
     * Provide targets the handler cannot render, with how the message names
     * each and the error the check raised, if any.
     *
     * @return iterable<string, array{0: mixed, 1: string, 2?: class-string<\Throwable>}>
     */
    public static function invalidTargetProvider(): iterable
    {
        yield 'not a string' => [42, 'int'];
        yield 'missing class' => ['App\Exceptions\MissingException', 'App\Exceptions\MissingException'];
        yield 'not an api exception' => [\RuntimeException::class, \RuntimeException::class];
        yield 'abstract' => [RateLimitedException::class, RateLimitedException::class];
        yield 'runtime status' => [HttpException::class, HttpException::class];
        yield 'without a status' => [StatuslessApiException::class, StatuslessApiException::class, \LogicException::class];
        yield 'without a code' => [CodelessApiException::class, CodelessApiException::class];
        yield 'code that is not an error code' => [self::FIXTURES . 'InvalidCodeException', self::FIXTURES . 'InvalidCodeException'];
        yield 'status that is not an http status' => [self::FIXTURES . 'IntegerStatusException', self::FIXTURES . 'IntegerStatusException'];
        yield 'protected status that is not an http status' => [self::FIXTURES . 'HiddenIntegerStatusException', self::FIXTURES . 'HiddenIntegerStatusException'];
        yield 'success status' => [self::FIXTURES . 'AcceptedException', self::FIXTURES . 'AcceptedException'];
        yield 'redirection status' => [self::FIXTURES . 'FoundException', self::FIXTURES . 'FoundException'];
        yield 'own constructor' => [self::FIXTURES . 'ConstructedException', self::FIXTURES . 'ConstructedException'];
        yield 'own instance status' => [self::FIXTURES . 'RestatedException', self::FIXTURES . 'RestatedException'];
    }

    /**
     * Test that a target the handler cannot render fails.
     *
     * @param  mixed  $target
     * @param  string  $named
     * @param  class-string<\Throwable>|null  $previous
     * @return void
     */
    #[DataProvider('invalidTargetProvider')]
    public function testTargetTheHandlerCannotRenderFails(mixed $target, string $named, ?string $previous = null): void
    {
        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => $target]);

        self::assertMapFails(sprintf(self::TARGET_MESSAGE, $named, NotFoundHttpException::class), $previous);
    }

    /**
     * Provide entries whose check raises an error, with the message each fails
     * with and the error raised.
     *
     * @return iterable<string, array{array<string, string>, string, class-string<\Throwable>}>
     */
    public static function erroringEntryProvider(): iterable
    {
        yield 'key' => [[self::EXPLODING => GoneException::class], sprintf(self::KEY_MESSAGE, self::EXPLODING), \Error::class];
        yield 'target' => [[NotFoundHttpException::class => self::EXPLODING], sprintf(self::TARGET_MESSAGE, self::EXPLODING, NotFoundHttpException::class), \Error::class];
        yield 'target status' => [
            [NotFoundHttpException::class => self::FIXTURES . 'UnknownStatusException'],
            sprintf(self::TARGET_MESSAGE, self::FIXTURES . 'UnknownStatusException', NotFoundHttpException::class),
            \ValueError::class,
        ];
    }

    /**
     * Test that an entry whose check raises an error fails as invalid, with the
     * error as its previous.
     *
     * @param  array<string, string>  $setting
     * @param  string  $message
     * @param  class-string<\Throwable>  $previous
     * @return void
     */
    #[DataProvider('erroringEntryProvider')]
    public function testEntryWhoseCheckRaisesAnErrorFails(array $setting, string $message, string $previous): void
    {
        $autoloader = static function (string $class): void {
            if ($class === self::EXPLODING) {
                throw new \Error('The class could not be loaded.');
            }
        };

        spl_autoload_register($autoloader);
        config()->set('api-toolkit.exceptions.map', $setting);

        try {
            self::assertMapFails($message, $previous);
        } finally {
            spl_autoload_unregister($autoloader);
        }
    }

    /**
     * Test that 4xx and 5xx targets pass, also behind a static status override
     * that hides a private status constant.
     *
     * @return void
     */
    public function testClientAndServerErrorTargetsPass(): void
    {
        config()->set('api-toolkit.exceptions.map', [
            NotFoundHttpException::class         => BadRequestException::class,
            MethodNotAllowedHttpException::class => TokenMismatchException::class,
            AuthenticationException::class       => ServiceUnavailableException::class,
            ValidationException::class           => self::FIXTURES . 'NetworkTimeoutException',
            AccessDeniedHttpException::class     => self::FIXTURES . 'PrivateIntegerStatusException',
        ]);

        $map = ExceptionMap::fromConfig();

        self::assertSame(BadRequestException::class, $map->targetFor(NotFoundHttpException::class));
        self::assertSame(TokenMismatchException::class, $map->targetFor(MethodNotAllowedHttpException::class));
        self::assertSame(ServiceUnavailableException::class, $map->targetFor(AuthenticationException::class));
        self::assertSame(self::FIXTURES . 'NetworkTimeoutException', $map->targetFor(ValidationException::class));
        self::assertSame(self::FIXTURES . 'PrivateIntegerStatusException', $map->targetFor(AccessDeniedHttpException::class));
    }

    /**
     * Assert that building the map fails with the given message, and with the
     * given error as its previous or none.
     *
     * @param  string  $message
     * @param  class-string<\Throwable>|null  $previous
     * @return void
     */
    private static function assertMapFails(string $message, ?string $previous = null): void
    {
        try {
            ExceptionMap::fromConfig();
        } catch (InvalidExceptionMapException $exception) {
            $failure = $exception->getPrevious();

            self::assertSame($message, $exception->getMessage());
            self::assertSame($previous, $failure === null ? null : $failure::class);

            return;
        }

        self::fail('The map was built.');
    }
}
