<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for the ServiceContext value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceContext::class)]
final class ServiceContextTest extends TestCase
{
    /**
     * Test that the constructor exposes all four properties.
     *
     * @return void
     */
    public function testConstructorExposesProperties(): void
    {
        $actor    = $this->makeActor();
        $metadata = ['ip' => '127.0.0.1'];

        $context = new ServiceContext(
            actor: $actor,
            correlationId: 'abc-123',
            source: ServiceSource::HTTP,
            metadata: $metadata,
        );

        self::assertSame($actor, $context->actor);
        self::assertSame('abc-123', $context->correlationId);
        self::assertSame(ServiceSource::HTTP, $context->source);
        self::assertSame($metadata, $context->metadata);
    }

    /**
     * Test that for() generates a non-empty correlation id when none is given.
     *
     * @return void
     */
    public function testForGeneratesCorrelationId(): void
    {
        $context = ServiceContext::for($this->makeActor());

        self::assertNotEmpty($context->correlationId);
    }

    /**
     * Test that for() preserves an explicit correlation id.
     *
     * @return void
     */
    public function testForPreservesExplicitCorrelationId(): void
    {
        $context = ServiceContext::for($this->makeActor(), correlationId: 'explicit-id-99');

        self::assertSame('explicit-id-99', $context->correlationId);
    }

    /**
     * Test that for() defaults the source to ServiceSource::INTERNAL.
     *
     * @return void
     */
    public function testForDefaultsSourceToInternal(): void
    {
        $context = ServiceContext::for($this->makeActor());

        self::assertSame(ServiceSource::INTERNAL, $context->source);
    }

    /**
     * Test that ServiceContext round-trips through PHP serialization.
     *
     * @return void
     */
    public function testRoundTripsThroughSerialization(): void
    {
        $actor    = $this->makeActor();
        $metadata = ['user-agent' => 'test/1.0', 'request-id' => 'req-42'];

        $context = ServiceContext::for(
            actor: $actor,
            source: ServiceSource::QUEUE,
            metadata: $metadata,
            correlationId: 'corr-xyz',
        );

        /** @var \SineMacula\ApiToolkit\Services\ServiceContext $restored */
        $restored = unserialize(serialize($context));

        self::assertInstanceOf(ServiceContext::class, $restored);
        self::assertSame('corr-xyz', $restored->correlationId);
        self::assertSame(ServiceSource::QUEUE, $restored->source);
        self::assertSame($metadata, $restored->metadata);
        self::assertSame($actor->actorType(), $restored->actor->actorType());
        self::assertSame($actor->actorIdentifier(), $restored->actor->actorIdentifier());
    }

    /**
     * Test that ServiceContext is immutable (final readonly class).
     *
     * @return void
     */
    public function testContextIsImmutable(): void
    {
        $reflection = new \ReflectionClass(ServiceContext::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    /**
     * Test that fromRequest() captures the client IP and user agent.
     *
     * @return void
     */
    public function testFromRequestCapturesIpAddressAndUserAgent(): void
    {
        $context = ServiceContext::fromRequest($this->makeRequest(), $this->makeActor());

        self::assertSame([
            ServiceContext::METADATA_IP         => '198.51.100.4',
            ServiceContext::METADATA_USER_AGENT => 'client/2.0',
        ], $context->metadata);
    }

    /**
     * Test that fromRequest() keeps both metadata keys with no user agent.
     *
     * @return void
     */
    public function testFromRequestKeepsTheUserAgentKeyWhenTheRequestHasNone(): void
    {
        $request = $this->makeRequest();
        $request->headers->remove('User-Agent');

        $context = ServiceContext::fromRequest($request, $this->makeActor());

        self::assertSame([
            ServiceContext::METADATA_IP         => '198.51.100.4',
            ServiceContext::METADATA_USER_AGENT => null,
        ], $context->metadata);
    }

    /**
     * Test that fromRequest() sets the source to HTTP and keeps the actor.
     *
     * @return void
     */
    public function testFromRequestSetsTheHttpSourceAndKeepsTheActor(): void
    {
        $actor   = $this->makeActor();
        $context = ServiceContext::fromRequest($this->makeRequest(), $actor);

        self::assertSame(ServiceSource::HTTP, $context->source);
        self::assertSame($actor, $context->actor);
    }

    /**
     * Test that the given metadata is merged over the captured keys.
     *
     * @return void
     */
    public function testFromRequestLetsTheGivenMetadataOverrideTheCapturedKeys(): void
    {
        $context = ServiceContext::fromRequest($this->makeRequest(), $this->makeActor(), [
            ServiceContext::METADATA_IP => '203.0.113.9',
            'tenant'                    => 'acme',
        ]);

        self::assertSame([
            ServiceContext::METADATA_IP         => '203.0.113.9',
            ServiceContext::METADATA_USER_AGENT => 'client/2.0',
            'tenant'                            => 'acme',
        ], $context->metadata);
    }

    /**
     * Test that fromRequest() uses X-Request-Id as the correlation id.
     *
     * @return void
     */
    public function testFromRequestUsesTheCorrelationHeader(): void
    {
        $context = ServiceContext::fromRequest($this->makeRequest(['HTTP_X_REQUEST_ID' => 'req-42']), $this->makeActor());

        self::assertSame('req-42', $context->correlationId);
    }

    /**
     * Test that fromRequest() trims the correlation header before using it.
     *
     * @return void
     */
    public function testFromRequestTrimsTheCorrelationHeader(): void
    {
        $context = ServiceContext::fromRequest($this->makeRequest(['HTTP_X_REQUEST_ID' => "  req-42\t"]), $this->makeActor());

        self::assertSame('req-42', $context->correlationId);
    }

    /**
     * Test that fromRequest() accepts a correlation id of the maximum length.
     *
     * @return void
     */
    public function testFromRequestAcceptsACorrelationIdOfTheMaximumLength(): void
    {
        $id      = str_repeat('a', 128);
        $context = ServiceContext::fromRequest($this->makeRequest(['HTTP_X_REQUEST_ID' => $id]), $this->makeActor());

        self::assertSame($id, $context->correlationId);
    }

    /**
     * Test that fromRequest() generates a correlation id without a header.
     *
     * @return void
     */
    public function testFromRequestGeneratesACorrelationIdWhenTheHeaderIsAbsent(): void
    {
        $context = ServiceContext::fromRequest($this->makeRequest(), $this->makeActor());

        self::assertTrue(Str::isUuid($context->correlationId));
    }

    /**
     * Test that fromRequest() reads the configured correlation header.
     *
     * @return void
     */
    public function testFromRequestHonoursACustomConfiguredHeader(): void
    {
        Config::set(ServiceContext::CORRELATION_HEADER_KEY, 'X-Trace-Id');

        $request = $this->makeRequest([
            'HTTP_X_REQUEST_ID' => 'request-id',
            'HTTP_X_TRACE_ID'   => 'trace-id',
        ]);

        self::assertSame('trace-id', ServiceContext::fromRequest($request, $this->makeActor())->correlationId);
    }

    /**
     * Test that fromRequest() falls back to X-Request-Id when unconfigured.
     *
     * @return void
     */
    public function testFromRequestReadsXRequestIdWhenNoHeaderIsConfigured(): void
    {
        Config::set('api-toolkit.services', []);

        $context = ServiceContext::fromRequest($this->makeRequest(['HTTP_X_REQUEST_ID' => 'req-42']), $this->makeActor());

        self::assertSame('req-42', $context->correlationId);
    }

    /**
     * Provide the configured header values that disable header reading.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function disabledHeaderProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    /**
     * Test that fromRequest() generates a correlation id when reading is off.
     *
     * @param  string|null  $header
     * @return void
     */
    #[DataProvider('disabledHeaderProvider')]
    public function testFromRequestGeneratesACorrelationIdWhenHeaderReadingIsDisabled(?string $header): void
    {
        Config::set(ServiceContext::CORRELATION_HEADER_KEY, $header);

        $context = ServiceContext::fromRequest($this->makeRequest(['HTTP_X_REQUEST_ID' => 'req-42']), $this->makeActor());

        self::assertTrue(Str::isUuid($context->correlationId));
    }

    /**
     * Provide correlation header values that must not be used as given.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidCorrelationIdProvider(): iterable
    {
        yield 'too long' => [str_repeat('a', 129)];
        yield 'blank' => ['   '];
        yield 'inner space' => ['req 42'];
        yield 'inner tab' => ["req\t42"];
        yield 'leading control character' => ["\x01req-42"];
        yield 'trailing control character' => ["req-42\x01"];
        yield 'delete character' => ["req-42\x7F"];
        yield 'non-ASCII character' => ['req-42-é'];
    }

    /**
     * Test that fromRequest() generates a correlation id for an unusable value.
     *
     * @param  string  $value
     * @return void
     */
    #[DataProvider('invalidCorrelationIdProvider')]
    public function testFromRequestGeneratesACorrelationIdForAnInvalidHeaderValue(string $value): void
    {
        $context = ServiceContext::fromRequest($this->makeRequest(['HTTP_X_REQUEST_ID' => $value]), $this->makeActor());

        self::assertTrue(Str::isUuid($context->correlationId));
    }

    /**
     * Test the documented values of the public constants.
     *
     * @return void
     */
    public function testPublicConstantsHaveTheDocumentedValues(): void
    {
        self::assertSame('ip', ServiceContext::METADATA_IP);
        self::assertSame('user_agent', ServiceContext::METADATA_USER_AGENT);
        self::assertSame('api-toolkit.services.correlation_header', ServiceContext::CORRELATION_HEADER_KEY);
    }

    /**
     * Build a request from a client IP with a user agent and extra server vars.
     *
     * @param  array<string, string>  $server
     * @return \Illuminate\Http\Request
     */
    private function makeRequest(array $server = []): Request
    {
        return Request::create('/', 'GET', server: [
            'REMOTE_ADDR'     => '198.51.100.4',
            'HTTP_USER_AGENT' => 'client/2.0',
            ...$server,
        ]);
    }

    /**
     * Build a lightweight in-test Actor stub.
     *
     * @return \SineMacula\ApiToolkit\Services\Contracts\Actor
     */
    private function makeActor(): Actor
    {
        return new StubActor;
    }
}
