<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ArticleRequestInput;
use Tests\Fixtures\OpenApi\PathExcludedController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathTaggedController;
use Tests\Fixtures\OpenApi\PathUnmappedController;
use Tests\Fixtures\OpenApi\Vendor\PathVendorController;
use Tests\Fixtures\OpenApi\WidgetShape;
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
     * Test that the index action offers both pagination shapes as a oneOf of
     * the length-aware and cursor collection envelopes and still references the
     * shared total-count header.
     *
     * @return void
     */
    public function testIndexEmitsBothPaginationShapesWithTotalCountHeader(): void
    {
        $this->registerRestRoutes();

        $response = $this->build()['/users']['get']['responses']['200'];
        $envelope = new EnvelopeBuilder;

        self::assertSame(
            [
                'oneOf' => [
                    $envelope->collectionEnvelope('#/components/schemas/User'),
                    $envelope->cursorCollectionEnvelope('#/components/schemas/User'),
                ],
            ],
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
     * Test that a route handled by a plain, non-authorized controller emits an
     * undocumented success operation tagged from the controller basename.
     *
     * @return void
     */
    public function testPlainControllerEmitsUndocumentedOperation(): void
    {
        $this->router()->get('plain', [PathPlainController::class, 'report']);

        $operation = $this->build()['/plain']['get'];

        self::assertSame(['PathPlain'], $operation['tags']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that an invokable controller emits an undocumented operation
     * carrying its verb, path parameters, controller-derived tag, and the
     * x-undocumented success envelope.
     *
     * @return void
     */
    public function testInvokableControllerEmitsUndocumentedOperation(): void
    {
        $this->router()->get('widgets/{widget}', PathInvokableController::class);

        $operation = $this->build()['/widgets/{widget}']['get'];

        self::assertSame(['PathInvokable'], $operation['tags']);
        self::assertSame('widget', $operation['parameters'][0]['name']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses']['200']['content']['application/json']['schema'],
        );
        self::assertArrayNotHasKey('401', $operation['responses']);
    }

    /**
     * Test that a non-REST action on an authorized controller falls through to
     * an undocumented operation rather than a resource operation.
     *
     * @return void
     */
    public function testNonRestActionEmitsUndocumentedOperation(): void
    {
        $this->router()->get('users/export', [PathFixtureController::class, 'export']);

        $operation = $this->build()['/users/export']['get'];

        self::assertArrayHasKey('200', $operation['responses']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a closure route emits an undocumented operation carrying the
     * x-undocumented success envelope and a tag derived from the URI.
     *
     * @return void
     */
    public function testClosureEmitsUndocumentedOperation(): void
    {
        $this->router()->get('health', static fn (): null => null);

        $operation = $this->build()['/health']['get'];

        self::assertSame(['health'], $operation['tags']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a closure scoped out with the undocumented route macro is
     * omitted from the audience just as an attribute-scoped route is.
     *
     * @return void
     */
    public function testClosureExcludedViaMacroIsOmitted(): void
    {
        $this->router()->get('secret', static fn (): null => null)->undocumented(); // @phpstan-ignore method.notFound

        self::assertArrayNotHasKey('/secret', $this->build());
    }

    /**
     * Test that a #[Tag] attribute overrides the derived tag, with a
     * method-level tag winning over the class-level one.
     *
     * @return void
     */
    public function testExplicitTagOverridesDerivedTag(): void
    {
        $this->router()->get('widgets', [PathTaggedController::class, 'index']);
        $this->router()->get('reports', [PathTaggedController::class, 'report']);

        $paths = $this->build();

        self::assertSame(['Widgets'], $paths['/widgets']['get']['tags']);
        self::assertSame(['Reports'], $paths['/reports']['get']['tags']);
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
     * Test that a route whose controller is defined under a blocklisted
     * namespace contributes no path while a route in the ordinary fixture
     * namespace still contributes.
     *
     * @return void
     */
    public function testBlocklistedNamespaceContributesNoPath(): void
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app->make('config');

        $config->set(
            'api-toolkit.openapi.exclude.namespaces',
            ['Tests\Fixtures\OpenApi\Vendor'],
        );

        $this->router()->get('vendor', [PathVendorController::class, 'index']);
        $this->router()->get('plain', [PathPlainController::class, 'index']);

        $paths = $this->build();

        self::assertArrayNotHasKey('/vendor', $paths);
        self::assertArrayHasKey('/plain', $paths);
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
     * Test that an authorized controller whose model has no registered resource
     * falls through to an undocumented operation rather than being skipped.
     *
     * @return void
     */
    public function testUnmappedModelEmitsUndocumentedOperation(): void
    {
        $this->router()->get('posts', [PathUnmappedController::class, 'index']);

        $operation = $this->build()['/posts']['get'];

        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a non-resource store action emits its success under 201 with
     * the shared undocumented envelope.
     *
     * @return void
     */
    public function testNonResourceStoreEmitsCreatedStatus(): void
    {
        $this->router()->post('posts', [PathUnmappedController::class, 'store']);

        $responses = $this->build()['/posts']['post']['responses'];

        self::assertArrayHasKey('201', $responses);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $responses['201']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a non-resource destroy action emits an empty 204 with no body.
     *
     * @return void
     */
    public function testNonResourceDestroyEmitsNoContent(): void
    {
        $this->router()->delete('posts/{post}', [PathUnmappedController::class, 'destroy']);

        $response = $this->build()['/posts/{post}']['delete']['responses']['204'];

        self::assertArrayNotHasKey('content', $response);
    }

    /**
     * Test that a non-resource action declaring a registered resource emits a
     * single-resource envelope referencing that resource's component schema.
     *
     * @return void
     */
    public function testResponseSchemaResourceActionEmitsSingleReference(): void
    {
        $this->router()->get('single', [PathResponseSchemaController::class, 'single']);

        $schema = $this->build()['/single']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
            $schema,
        );
    }

    /**
     * Test that a non-resource action declaring a registered resource as a
     * collection emits the length-aware collection envelope for that resource.
     *
     * @return void
     */
    public function testResponseSchemaCollectionActionEmitsCollectionEnvelope(): void
    {
        $this->router()->get('collection', [PathResponseSchemaController::class, 'list']);

        $schema = $this->build()['/collection']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $schema,
        );
    }

    /**
     * Test that a non-resource action declaring a self-describing DTO inlines
     * the DTO's schema fragment under the single envelope's data property.
     *
     * @return void
     */
    public function testResponseSchemaDtoActionInlinesFragment(): void
    {
        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);

        $schema = $this->build()['/widget']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(WidgetShape::schema(), $schema['properties']['data']);
        self::assertSame(['data'], $schema['required']);
    }

    /**
     * Test that a non-resource action carrying no directive still emits the
     * shared x-undocumented success envelope.
     *
     * @return void
     */
    public function testUndeclaredNonResourceActionStaysUndocumented(): void
    {
        $this->router()->get('bare', [PathResponseSchemaController::class, 'bare']);

        $schema = $this->build()['/bare']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame($this->undocumentedEnvelope(), $schema);
    }

    /**
     * Test that a POST write operation carries a requestBody translated from
     * the action's directive-named rules source.
     *
     * @return void
     */
    public function testWriteOperationCarriesRequestBody(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $operation = $this->build()['/articles']['post'];

        self::assertSame(
            [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => (new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder))
                            ->translate(ArticleRequestInput::rules()),
                    ],
                ],
            ],
            $operation['requestBody'],
        );
    }

    /**
     * Test that a PUT/PATCH write operation carries a requestBody discovered
     * from a type-hinted rules-source parameter.
     *
     * @return void
     */
    public function testUpdateOperationCarriesRequestBodyFromTypeHint(): void
    {
        $this->router()->match(['PUT', 'PATCH'], 'articles/{article}', [PathRequestBodyController::class, 'update']);

        $operation = $this->build()['/articles/{article}']['put'];

        self::assertArrayHasKey('requestBody', $operation);
        self::assertArrayHasKey(
            'title',
            $operation['requestBody']['content']['application/json']['schema']['properties'],
        );
    }

    /**
     * Test that a GET read operation carries no requestBody.
     *
     * @return void
     */
    public function testReadOperationHasNoRequestBody(): void
    {
        $this->router()->get('articles', [PathRequestBodyController::class, 'index']);

        self::assertArrayNotHasKey('requestBody', $this->build()['/articles']['get']);
    }

    /**
     * Test that a DELETE operation carries no requestBody.
     *
     * @return void
     */
    public function testDeleteOperationHasNoRequestBody(): void
    {
        $this->router()->delete('articles/{article}', [PathRequestBodyController::class, 'destroy']);

        self::assertArrayNotHasKey('requestBody', $this->build()['/articles/{article}']['delete']);
    }

    /**
     * Test that attaching a requestBody leaves the operation's response body
     * unchanged.
     *
     * @return void
     */
    public function testWriteOperationResponseBodyUnchanged(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $responses = $this->build()['/articles']['post']['responses'];

        self::assertArrayHasKey('201', $responses);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $responses['201']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a route with no auth middleware is documented as public,
     * carrying an explicit empty security list.
     *
     * @return void
     */
    public function testPublicOperationCarriesEmptySecurity(): void
    {
        $this->registerRestRoutes();

        self::assertSame([], $this->build()['/users']['get']['security']);
    }

    /**
     * Test that a single-guard auth route carries the guard driver's scheme as
     * a single requirement.
     *
     * @return void
     */
    public function testSingleGuardOperationCarriesOneRequirement(): void
    {
        $this->configureGuards();
        $this->router()->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api');

        self::assertSame([['bearerAuth' => []]], $this->build()['/users']['get']['security']);
    }

    /**
     * Test that guards mapping to different schemes emit separate single-scheme
     * requirements, expressing the OR of the two.
     *
     * @return void
     */
    public function testDifferentSchemeGuardsEmitSeparateRequirements(): void
    {
        $this->configureGuards();
        $this->router()->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api,admin');

        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $this->build()['/users']['get']['security'],
        );
    }

    /**
     * Test that guards mapping to the same scheme collapse to one requirement,
     * never revealing the second guard.
     *
     * @return void
     */
    public function testSameSchemeGuardsDedupeToOneRequirement(): void
    {
        $this->configureGuards();
        $this->router()->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api,sanctum');

        self::assertSame([['bearerAuth' => []]], $this->build()['/users']['get']['security']);
    }

    /**
     * Test that a bare auth middleware resolves the application's default
     * guard.
     *
     * @return void
     */
    public function testBareAuthResolvesTheDefaultGuard(): void
    {
        $this->configureGuards();
        $this->router()->get('users', [PathFixtureController::class, 'index'])->middleware('auth');

        self::assertSame([['cookieAuth' => []]], $this->build()['/users']['get']['security']);
    }

    /**
     * Test that an audience documenting no route yields an empty paths object.
     *
     * @return void
     */
    public function testNoDocumentedRoutesYieldsEmptyPaths(): void
    {
        // Under the allowlist posture no route opts in, so even the framework's
        // default routes are excluded and the paths object stays empty.
        self::assertSame([], $this->build('partner', QuerySurface::POSTURE_ALLOWLIST));
    }

    /**
     * The expected shared data envelope wrapping the x-undocumented marker.
     *
     * @return array<string, mixed>
     */
    private function undocumentedEnvelope(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['data' => ['x-undocumented' => true]],
            'required'   => ['data'],
        ];
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

        return new PathBuilder(
            $this->router(),
            $catalogue,
            new AudienceResolver,
            new DocumentableRouteFilter,
            new EnvelopeBuilder,
            new TagResolver,
            new ResponseSchemaResolver($catalogue, new EnvelopeBuilder),
            new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder)),
            new SecuritySchemeResolver(new SecuritySchemeMapper),
        );
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

    /**
     * Configure the auth guards the security tests resolve drivers from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app->make('config');

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
        $config->set('auth.guards.web', ['driver' => 'session']);
        $config->set('auth.guards.sanctum', ['driver' => 'sanctum']);
        $config->set('auth.guards.admin', ['driver' => 'basic']);
    }
}
