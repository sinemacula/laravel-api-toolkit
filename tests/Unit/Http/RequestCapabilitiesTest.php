<?php

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;
use Tests\TestCase;

/**
 * Unit tests for the RequestCapabilities value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestCapabilities::class)]
class RequestCapabilitiesTest extends TestCase
{
    /**
     * Test that fromRequest returns the stored instance.
     *
     * @return void
     */
    public function testFromRequestReturnsStoredInstance(): void
    {
        $request      = Request::create('/test');
        $capabilities = $this->createCapabilities(include_trashed: true);

        RequestCapabilities::storeOnRequest($request, $capabilities);

        $retrieved = RequestCapabilities::fromRequest($request);

        static::assertSame($capabilities, $retrieved);
        static::assertTrue($retrieved->includeTrashed());
    }

    /**
     * Test that fromRequest returns a default instance when no attribute
     * is stored.
     *
     * @return void
     */
    public function testFromRequestReturnsDefaultWhenAttributeNotSet(): void
    {
        $request      = Request::create('/test');
        $capabilities = RequestCapabilities::fromRequest($request);

        static::assertFalse($capabilities->includeTrashed());
        static::assertFalse($capabilities->onlyTrashed());
        static::assertFalse($capabilities->expectsExport());
        static::assertFalse($capabilities->expectsCsv());
        static::assertFalse($capabilities->expectsXml());
        static::assertFalse($capabilities->expectsPdf());
        static::assertFalse($capabilities->expectsStream());
    }

    /**
     * Test that resolve detects include_trashed query parameter.
     *
     * @return void
     */
    public function testResolveDetectsIncludeTrashed(): void
    {
        $request      = Request::create('/test', 'GET', ['include_trashed' => 'true']);
        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->includeTrashed());
        static::assertFalse($capabilities->onlyTrashed());
    }

    /**
     * Test that resolve detects only_trashed query parameter.
     *
     * @return void
     */
    public function testResolveDetectsOnlyTrashed(): void
    {
        $request      = Request::create('/test', 'GET', ['only_trashed' => 'true']);
        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->onlyTrashed());
        static::assertFalse($capabilities->includeTrashed());
    }

    /**
     * Test that resolve detects CSV via Accept header and supported
     * formats.
     *
     * @return void
     */
    public function testResolveDetectsExpectsCsv(): void
    {
        config()->set('api-toolkit.exports.enabled', true);
        config()->set('api-toolkit.exports.supported_formats', ['csv', 'xml']);

        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::TEXT_CSV->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->expectsCsv());
        static::assertTrue($capabilities->expectsExport());
    }

    /**
     * Test that resolve detects XML via Accept header and supported
     * formats.
     *
     * @return void
     */
    public function testResolveDetectsExpectsXml(): void
    {
        config()->set('api-toolkit.exports.enabled', true);
        config()->set('api-toolkit.exports.supported_formats', ['csv', 'xml']);

        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::APPLICATION_XML->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->expectsXml());
        static::assertTrue($capabilities->expectsExport());
    }

    /**
     * Test that resolve detects expectsExport when exports are enabled
     * and CSV or XML is detected.
     *
     * @return void
     */
    public function testResolveDetectsExpectsExport(): void
    {
        config()->set('api-toolkit.exports.enabled', true);
        config()->set('api-toolkit.exports.supported_formats', ['csv']);

        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::TEXT_CSV->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->expectsExport());
    }

    /**
     * Test that resolve detects PDF via Accept header.
     *
     * @return void
     */
    public function testResolveDetectsExpectsPdf(): void
    {
        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::APPLICATION_PDF->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->expectsPdf());
    }

    /**
     * Test that resolve detects stream via Accept header.
     *
     * @return void
     */
    public function testResolveDetectsExpectsStream(): void
    {
        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::TEXT_EVENT_STREAM->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->expectsStream());
    }

    /**
     * Test that storeOnRequest sets the attribute on the request.
     *
     * @return void
     */
    public function testStoreOnRequestSetsAttribute(): void
    {
        $request      = Request::create('/test');
        $capabilities = $this->createCapabilities(expects_pdf: true);

        RequestCapabilities::storeOnRequest($request, $capabilities);

        $stored = $request->attributes->get(RequestCapabilities::class);

        static::assertInstanceOf(RequestCapabilities::class, $stored);
        static::assertTrue($stored->expectsPdf());
    }

    /**
     * Test that expectsCsv returns false when the format is not in
     * supported_formats.
     *
     * @return void
     */
    public function testResolveReturnsFalseForCsvWhenFormatNotSupported(): void
    {
        config()->set('api-toolkit.exports.enabled', true);
        config()->set('api-toolkit.exports.supported_formats', ['xml']);

        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::TEXT_CSV->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertFalse($capabilities->expectsCsv());
        static::assertFalse($capabilities->expectsExport());
    }

    /**
     * Test that expectsExport returns false when exports are disabled.
     *
     * @return void
     */
    public function testResolveReturnsFalseForExportWhenDisabled(): void
    {
        config()->set('api-toolkit.exports.enabled', false);
        config()->set('api-toolkit.exports.supported_formats', ['csv', 'xml']);

        $request = Request::create('/test');
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::TEXT_CSV->getMimeType());

        $capabilities = RequestCapabilities::resolve($request);

        static::assertTrue($capabilities->expectsCsv());
        static::assertFalse($capabilities->expectsExport());
    }

    /**
     * Test that all 7 accessor methods return the correct corresponding
     * values.
     *
     * @return void
     */
    public function testAllAccessorsReturnCorrectValues(): void
    {
        $capabilities = $this->createCapabilities(
            include_trashed: true,
            only_trashed: true,
            expects_export: true,
            expects_csv: true,
            expects_xml: true,
            expects_pdf: true,
            expects_stream: true,
        );

        static::assertTrue($capabilities->includeTrashed());
        static::assertTrue($capabilities->onlyTrashed());
        static::assertTrue($capabilities->expectsExport());
        static::assertTrue($capabilities->expectsCsv());
        static::assertTrue($capabilities->expectsXml());
        static::assertTrue($capabilities->expectsPdf());
        static::assertTrue($capabilities->expectsStream());
    }

    /**
     * Create a RequestCapabilities instance via reflection for testing.
     *
     * @param  bool  $include_trashed
     * @param  bool  $only_trashed
     * @param  bool  $expects_export
     * @param  bool  $expects_csv
     * @param  bool  $expects_xml
     * @param  bool  $expects_pdf
     * @param  bool  $expects_stream
     * @return \SineMacula\ApiToolkit\Http\RequestCapabilities
     */
    private function createCapabilities(
        bool $include_trashed = false,
        bool $only_trashed = false,
        bool $expects_export = false,
        bool $expects_csv = false,
        bool $expects_xml = false,
        bool $expects_pdf = false,
        bool $expects_stream = false,
    ): RequestCapabilities {

        $reflection  = new \ReflectionClass(RequestCapabilities::class);
        $constructor = $reflection->getConstructor();

        assert($constructor !== null);

        $constructor->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();

        $constructor->invoke(
            $instance,
            $include_trashed,
            $only_trashed,
            $expects_export,
            $expects_csv,
            $expects_xml,
            $expects_pdf,
            $expects_stream,
        );

        return $instance;
    }
}
