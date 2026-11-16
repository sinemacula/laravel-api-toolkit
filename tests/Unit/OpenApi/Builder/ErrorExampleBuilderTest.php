<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ErrorCatalogueMismatchException;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use Tests\Fixtures\OpenApi\CodelessApiException;
use Tests\Fixtures\OpenApi\QuotaExceededException;

/**
 * Tests for the ErrorExampleBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorExampleBuilder::class)]
final class ErrorExampleBuilderTest extends TestCase
{
    /**
     * Test that an exception references the example named after its code.
     *
     * @return void
     */
    public function testReferenceNamesTheExampleAfterTheCode(): void
    {
        self::assertSame(
            ['Error10103' => ['$ref' => '#/components/examples/Error10103']],
            $this->builder()->reference(NotFoundException::class),
        );
    }

    /**
     * Test that an exception without a CODE references no example.
     *
     * @return void
     */
    public function testExceptionWithoutACodeReferencesNoExample(): void
    {
        self::assertSame([], $this->builder()->reference(CodelessApiException::class));
    }

    /**
     * Test that an exception sharing a code and status references the code's
     * example, which carries the catalogue's text.
     *
     * @return void
     */
    public function testExceptionSharingACodeReferencesTheCodesExample(): void
    {
        $builder = $this->builder();

        self::assertSame(
            ['Error10107' => ['$ref' => '#/components/examples/Error10107']],
            $builder->reference(QuotaExceededException::class),
        );
        self::assertSame(
            ['Error10107' => [
                'summary' => 'Too Many Requests',
                'value'   => ['error' => ['status' => 429, 'code' => 10107, 'title' => 'Too Many Requests', 'detail' => 'Slow down.']],
            ]],
            $builder->build($this->paths($builder->reference(QuotaExceededException::class))),
        );
    }

    /**
     * Test that a code missing from the catalogue fails.
     *
     * @return void
     */
    public function testCodeMissingFromTheCatalogueFails(): void
    {
        $this->expectException(ErrorCatalogueMismatchException::class);
        $this->expectExceptionMessage(sprintf(
            'The error code 10102 of [%s] is not in the error catalogue; add its namespace to api-toolkit.openapi.exception_namespaces.',
            ForbiddenException::class,
        ));

        $this->builder()->reference(ForbiddenException::class);
    }

    /**
     * Test that a status the catalogue documents otherwise fails.
     *
     * @return void
     */
    public function testStatusTheCatalogueDocumentsOtherwiseFails(): void
    {
        $builder = $this->builder([
            new ErrorDescriptor(code: 10103, httpStatus: 410, title: 'Gone', detail: 'Gone.', source: 'App\Exceptions\RemovedException'),
        ]);

        $this->expectException(ErrorCatalogueMismatchException::class);
        $this->expectExceptionMessage(sprintf(
            '[%1$s] answers 404 with error code 10103, which the error catalogue documents for [App\Exceptions\RemovedException] as 410; give [%1$s] its own error code.',
            NotFoundException::class,
        ));

        $builder->reference(NotFoundException::class);
    }

    /**
     * Test that only the referenced examples are built, ordered by code.
     *
     * @return void
     */
    public function testBuildEmitsOnlyTheReferencedExamplesOrderedByCode(): void
    {
        $builder = $this->builder();
        $paths   = $this->paths($builder->reference(TooManyRequestsException::class));

        $paths['/users']['get']['responses'][404]['content']['application/json']['examples'] = $builder->reference(NotFoundException::class);
        $paths['/users']['get']['responses'][500]['content']['application/json']['examples'] = ['Error10001' => ['$ref' => '#/components/examples/Error10001']];

        self::assertSame(['Error10001', 'Error10103', 'Error10107'], array_keys($builder->build($paths)));
    }

    /**
     * Test that the summary falls back to the detail and a null title is
     * dropped from the value.
     *
     * @return void
     */
    public function testUntitledExampleIsSummarisedByItsDetail(): void
    {
        $builder = $this->builder([
            new ErrorDescriptor(code: 10113, httpStatus: 500, title: null, detail: 'A generic error occurred.'),
        ]);

        self::assertSame(
            ['Error10113' => [
                'summary' => 'A generic error occurred.',
                'value'   => ['error' => ['status' => 500, 'code' => 10113, 'detail' => 'A generic error occurred.']],
            ]],
            $builder->build($this->paths(['Error10113' => ['$ref' => '#/components/examples/Error10113']])),
        );
    }

    /**
     * Test that only references in an examples map count, not one inside a
     * payload, a placeholder object, or a reference to another component.
     *
     * @return void
     */
    public function testOnlyStructuralExampleReferencesCount(): void
    {
        $paths = $this->paths([
            'Inline'      => ['value' => ['$ref' => '#/components/examples/Error10103']],
            'Placeholder' => new \stdClass,
            'Callback'    => ['$ref' => '#/components/callback/Error10001'],
            'External'    => ['$ref' => 'https://example.com/examples.json#/Error10001'],
            'Error10107'  => ['$ref' => '#/components/examples/Error10107'],
        ]);

        $paths['/users']['parameters'] = [['name' => 'id']];
        $paths['/users']['summary']    = 'Users';

        self::assertSame(['Error10107'], array_keys($this->builder()->build($paths)));
    }

    /**
     * Test that no reference builds no example.
     *
     * @return void
     */
    public function testNoReferenceBuildsNoExample(): void
    {
        self::assertSame([], $this->builder()->build([]));
        self::assertSame([], $this->builder()->build(['/users' => ['get' => ['responses' => [200 => ['description' => 'OK']]]]]));
    }

    /**
     * Test that the catalogue is read once per builder.
     *
     * @return void
     */
    public function testCatalogueIsReadOncePerBuilder(): void
    {
        $catalogue = $this->createMock(MetadataCatalogue::class);
        $catalogue->expects(self::once())->method('getErrorCatalogue')->willReturn($this->descriptors());

        $builder = new ErrorExampleBuilder($catalogue);

        $builder->reference(NotFoundException::class);
        $builder->reference(TooManyRequestsException::class);
        $builder->build($this->paths($builder->reference(NotFoundException::class)));
    }

    /**
     * Build the paths of one operation whose 429 response carries the given
     * examples map.
     *
     * @param  array<string, mixed>  $examples
     * @return array<string, array<string, mixed>>
     */
    private function paths(array $examples): array
    {
        return [
            '/users' => [
                'get' => [
                    'responses' => [
                        429 => ['description' => 'Too many requests.', 'content' => ['application/json' => ['examples' => $examples]]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build an ErrorExampleBuilder backed by the given descriptors.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>|null  $descriptors
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder
     */
    private function builder(?array $descriptors = null): ErrorExampleBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getErrorCatalogue')->willReturn($descriptors ?? $this->descriptors());

        return new ErrorExampleBuilder($catalogue);
    }

    /**
     * Build a catalogue out of code order, without the forbidden code.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    private function descriptors(): array
    {
        return [
            new ErrorDescriptor(code: 10107, httpStatus: 429, title: 'Too Many Requests', detail: 'Slow down.', source: TooManyRequestsException::class),
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'Missing.', source: NotFoundException::class),
            new ErrorDescriptor(code: 10001, httpStatus: 500, title: 'Unknown Error', detail: 'Oh no!', source: null),
        ];
    }
}
