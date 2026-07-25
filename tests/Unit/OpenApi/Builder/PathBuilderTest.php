<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathExcludedController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathUnmappedController;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the path builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PathBuilder::class)]
final class PathBuilderTest extends TestCase
{
    /**
     * Test that every REST route yields its OpenAPI path template while
     * non-documentable routes contribute nothing.
     *
     * @return void
     */
    public function testEmitsPathTemplatesForRestRoutes(): void
    {
        $this->registerRestRoutes();

        $paths = $this->build();

        self::assertArrayHasKey('/users', $paths);
        self::assertArrayHasKey('/users/{user}', $paths);
        self::assertArrayNotHasKey('/users/export', $paths);
    }

    /**
     * Test that the collection and item URIs group their verbs under a single
     * shared path item.
     *
     * @return void
     */
    public function testGroupsVerbsOfSameUriUnderOnePathItem(): void
    {
        $this->registerRestRoutes();

        $paths = $this->build();

        self::assertSame(['get', 'post'], array_keys($paths['/users']));
        self::assertSame(['get', 'put', 'patch', 'delete'], array_keys($paths['/users/{user}']));
    }

    /**
     * Test that the index action emits the length-aware collection envelope and
     * references the shared total-count header.
     *
     * @return void
     */
    public function testIndexEmitsCollectionEnvelopeWithTotalCountHeader(): void
    {
        $this->registerRestRoutes();

        $response = $this->build()['/users']['get']['responses']['200'];

        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $response['content']['application/json']['schema'],
        );
        self::assertSame(
            '#/components/headers/Total-Count',
            $response['headers']['Total-Count']['$ref'],
        );
    }

    /**
     * Test that the show action emits a single-resource envelope under 200.
     *
     * @return void
     */
    public function testShowEmitsSingleEnvelope(): void
    {
        $this->registerRestRoutes();

        $response = $this->build()['/users/{user}']['get']['responses'];

        self::assertArrayHasKey('200', $response);
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
            $response['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that the store action emits a single-resource envelope under 201.
     *
     * @return void
     */
    public function testStoreEmitsSingleEnvelopeUnder201(): void
    {
        $this->registerRestRoutes();

        $response = $this->build()['/users']['post']['responses'];

        self::assertArrayHasKey('201', $response);
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
            $response['201']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that the update action maps both the PUT and PATCH verbs to the same
     * single-resource operation under 200.
     *
     * @return void
     */
    public function testUpdateMapsBothPutAndPatch(): void
    {
        $this->registerRestRoutes();

        $item = $this->build()['/users/{user}'];

        self::assertSame($item['put'], $item['patch']);
        self::assertArrayHasKey('200', $item['put']['responses']);
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
            $item['put']['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that the destroy action emits an empty 204 response with no body.
     *
     * @return void
     */
    public function testDestroyEmitsNoContent(): void
    {
        $this->registerRestRoutes();

        $response = $this->build()['/users/{user}']['delete']['responses'];

        self::assertArrayHasKey('204', $response);
        self::assertArrayNotHasKey('content', $response['204']);
    }

    /**
     * Test that the bodyless collection index carries only the baseline error
     * statuses that apply to it, omitting the resource and validation statuses.
     *
     * @return void
     */
    public function testIndexCarriesCollectionBaselineErrors(): void
    {
        $this->registerRestRoutes();

        $responses = $this->build()['/users']['get']['responses'];

        foreach (['401', '403', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('404', $responses);
        self::assertArrayNotHasKey('422', $responses);
    }

    /**
     * Test that a single-resource show carries the not-found status while a
     * bodyless read omits the validation status.
     *
     * @return void
     */
    public function testShowCarriesResourceBaselineWithNotFound(): void
    {
        $this->registerRestRoutes();

        $responses = $this->build()['/users/{user}']['get']['responses'];

        foreach (['401', '403', '404', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('422', $responses);
    }

    /**
     * Test that a create carries the validation status yet omits not-found, as
     * it addresses the collection rather than an existing resource.
     *
     * @return void
     */
    public function testStoreCarriesValidationBaseline(): void
    {
        $this->registerRestRoutes();

        $responses = $this->build()['/users']['post']['responses'];

        foreach (['401', '403', '422', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('404', $responses);
    }

    /**
     * Test that a destroy carries its no-content success alongside the resource
     * baseline error statuses and omits the validation status.
     *
     * @return void
     */
    public function testDestroyCarriesBaselineErrorsAlongsideNoContent(): void
    {
        $this->registerRestRoutes();

        $responses = $this->build()['/users/{user}']['delete']['responses'];

        foreach (['204', '401', '403', '404', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('422', $responses);
    }

    /**
     * Test that a baseline-only status references the shared error-envelope
     * schema under its standard phrase and carries no examples.
     *
     * @return void
     */
    public function testBaselineErrorReferencesSharedEnvelopeSchema(): void
    {
        $this->registerRestRoutes();

        $response = $this->build()['/users']['get']['responses']['401'];

        self::assertSame('Unauthenticated.', $response['description']);
        self::assertSame(
            ['$ref' => '#/components/schemas/ErrorEnvelope'],
            $response['content']['application/json']['schema'],
        );
        self::assertArrayNotHasKey('examples', $response['content']['application/json']);
    }

    /**
     * Test that a thrown ApiException whose status coincides with a baseline
     * status collapses into one response keeping the baseline phrase and
     * carrying the specific code as a named example.
     *
     * @return void
     */
    public function testThrownExceptionCollidesWithBaselineStatus(): void
    {
        $this->registerRestRoutes();

        $notFound = $this->build()['/users/{user}']['get']['responses']['404'];

        self::assertSame('The requested resource does not exist.', $notFound['description']);

        $media = $notFound['content']['application/json'];

        self::assertSame(['$ref' => '#/components/schemas/ErrorEnvelope'], $media['schema']);
        self::assertArrayHasKey('NotFoundException', $media['examples']);
        self::assertSame(
            ['status' => 404, 'code' => 10103],
            $media['examples']['NotFoundException']['value']['error'],
        );
    }

    /**
     * Test that a thrown ApiException whose status is not part of the baseline
     * surfaces under its own status with a named example carrying its code.
     *
     * @return void
     */
    public function testThrownExceptionSurfacesUnderItsOwnStatus(): void
    {
        $this->registerRestRoutes();

        $responses = $this->build()['/users']['post']['responses'];

        self::assertArrayHasKey('409', $responses);
        self::assertSame('Conflict.', $responses['409']['description']);
        self::assertSame(
            ['status' => 409, 'code' => 10108],
            $responses['409']['content']['application/json']['examples']['ConflictException']['value']['error'],
        );
    }

    /**
     * Test that the merged responses carry exactly one entry per status, with
     * the success and error statuses coexisting without collision.
     *
     * @return void
     */
    public function testResponsesHaveExactlyOneEntryPerStatus(): void
    {
        $this->registerRestRoutes();

        $statuses = array_keys($this->build()['/users']['post']['responses']);

        sort($statuses);

        self::assertSame([201, 401, 403, 409, 422, 500], $statuses);
    }

    /**
     * Test that every operation is tagged with the resource schema name.
     *
     * @return void
     */
    public function testOperationsAreTaggedWithSchemaName(): void
    {
        $this->registerRestRoutes();

        $paths = $this->build();

        self::assertSame(['User'], $paths['/users']['get']['tags']);
        self::assertSame(['User'], $paths['/users/{user}']['delete']['tags']);
    }

    /**
     * Test that path parameters are derived from the route segments.
     *
     * @return void
     */
    public function testPathParametersAreDerivedFromRoute(): void
    {
        $this->registerRestRoutes();

        $parameters = $this->build()['/users/{user}']['get']['parameters'];

        self::assertSame([
            [
                'name'     => 'user',
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'string'],
            ],
        ], $parameters);
    }

    /**
     * Test that a collection route carries no path parameters.
     *
     * @return void
     */
    public function testCollectionRouteHasNoPathParameters(): void
    {
        $this->registerRestRoutes();

        self::assertArrayNotHasKey('parameters', $this->build()['/users']['get']);
    }

    /**
     * Test that an optional route parameter renders as a required path
     * parameter without its trailing optional marker.
     *
     * @return void
     */
    public function testOptionalParameterRendersWithoutMarker(): void
    {
        $this->router()->get('users/{user?}', [PathFixtureController::class, 'show']);

        $paths = $this->build();

        self::assertArrayHasKey('/users/{user}', $paths);
        self::assertSame('user', $paths['/users/{user}']['get']['parameters'][0]['name']);
    }

    /**
     * Test that a route handled by a non-authorized controller is skipped.
     *
     * @return void
     */
    public function testNonAuthorizedControllerRouteIsSkipped(): void
    {
        $this->router()->get('plain', [PathPlainController::class, 'index']);

        self::assertArrayNotHasKey('/plain', $this->build());
    }

    /**
     * Test that a closure route is skipped.
     *
     * @return void
     */
    public function testClosureRouteIsSkipped(): void
    {
        $this->router()->get('closure', static fn (): null => null);

        self::assertArrayNotHasKey('/closure', $this->build());
    }

    /**
     * Test that a route whose action is not a REST action is skipped.
     *
     * @return void
     */
    public function testNonRestActionIsSkipped(): void
    {
        $this->router()->get('users/export', [PathFixtureController::class, 'export']);

        self::assertArrayNotHasKey('/users/export', $this->build());
    }

    /**
     * Test that a route excluded from the audience is omitted from that
     * audience while remaining documented in another.
     *
     * @return void
     */
    public function testRouteExcludedFromAudienceIsOmitted(): void
    {
        $this->router()->get('excluded', [PathExcludedController::class, 'index']);

        self::assertArrayNotHasKey('/excluded', $this->build('public'));
        self::assertArrayHasKey('/excluded', $this->build('internal'));
    }

    /**
     * Test that an allowlist audience excludes routes that opt into nothing.
     *
     * @return void
     */
    public function testAllowlistPostureExcludesUndeclaredRoutes(): void
    {
        $this->registerRestRoutes();

        self::assertSame([], $this->build('partner', QuerySurface::POSTURE_ALLOWLIST));
    }

    /**
     * Test that a route whose model has no registered resource is skipped.
     *
     * @return void
     */
    public function testUnmappedModelRouteIsSkipped(): void
    {
        $this->router()->get('posts', [PathUnmappedController::class, 'index']);

        self::assertArrayNotHasKey('/posts', $this->build());
    }

    /**
     * Test that an empty route table yields an empty paths object.
     *
     * @return void
     */
    public function testEmptyRouteTableYieldsEmptyPaths(): void
    {
        self::assertSame([], $this->build());
    }

    /**
     * Register the full REST route set on the application router.
     *
     * @return void
     */
    private function registerRestRoutes(): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index']);
        $router->post('users', [PathFixtureController::class, 'store']);
        $router->get('users/{user}', [PathFixtureController::class, 'show']);
        $router->match(['PUT', 'PATCH'], 'users/{user}', [PathFixtureController::class, 'update']);
        $router->delete('users/{user}', [PathFixtureController::class, 'destroy']);
    }

    /**
     * Build the paths object for the given audience and posture.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @return array<string, array<string, mixed>>
     */
    private function build(string $audience = 'public', string $posture = QuerySurface::POSTURE_BLOCKLIST): array
    {
        return $this->builder()->build($audience, $posture);
    }

    /**
     * Build a path builder backed by a catalogue mapping the user model to its
     * resource.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder
     */
    private function builder(): PathBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([User::class => UserResource::class]);

        return new PathBuilder($this->router(), $catalogue, new AudienceResolver, new EnvelopeBuilder);
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }
}
