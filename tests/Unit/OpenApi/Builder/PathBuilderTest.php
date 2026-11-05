<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ArticleRequestInput;
use Tests\Fixtures\OpenApi\PathArticleController;
use Tests\Fixtures\OpenApi\PathAttributelessController;
use Tests\Fixtures\OpenApi\PathErrorController;
use Tests\Fixtures\OpenApi\PathExcludedController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathHeaderCollisionController;
use Tests\Fixtures\OpenApi\PathHeaderedUserController;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
use Tests\Fixtures\OpenApi\PathStatusConflictController;
use Tests\Fixtures\OpenApi\PathStatusUserController;
use Tests\Fixtures\OpenApi\PathTaggedController;
use Tests\Fixtures\OpenApi\PathUnmappedController;
use Tests\Fixtures\OpenApi\PendingSignInShape;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\OpenApi\Vendor\PathVendorController;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\ArticleResource;
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
     * Test that the index action offers both pagination shapes as an anyOf of
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
            'A paginated collection of resources. Length-aware by default; sending ?cursor or ?pagination=cursor returns the cursor shape.',
            $response['description'],
        );
        self::assertSame(
            [
                'anyOf' => [
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
        self::assertSame('The requested resource.', $response['200']['description']);
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
        self::assertSame('The newly created resource.', $response['201']['description']);
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
        self::assertSame('The updated resource.', $item['put']['responses']['200']['description']);
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
        self::assertSame('The resource was deleted.', $response['204']['description']);
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
        $this->registerRestRoutes('auth');

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
        $this->registerRestRoutes('auth');

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
        $this->registerRestRoutes('auth');

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
        $this->registerRestRoutes('auth');

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
        $this->registerRestRoutes('auth');

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
        $this->registerRestRoutes('auth');

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
     * Test that path parameters are derived from the route segments and lead
     * the parameter list, ahead of the shared query references.
     *
     * @return void
     */
    public function testPathParametersAreDerivedFromRoute(): void
    {
        $this->registerRestRoutes();

        $parameters = $this->build()['/users/{user}']['get']['parameters'];

        self::assertSame([
            'name'     => 'user',
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'string'],
        ], $parameters[0]);
        self::assertSame([], $this->pathParametersIn(array_slice($parameters, 1)));
    }

    /**
     * Test that a collection route carries no path parameters.
     *
     * @return void
     */
    public function testCollectionRouteHasNoPathParameters(): void
    {
        $this->registerRestRoutes();

        self::assertSame([], $this->pathParametersIn($this->build()['/users']['get']['parameters']));
    }

    /**
     * Test that an index references the whole shared query grammar so a
     * generated client sees the parameters the document defines.
     *
     * @return void
     */
    public function testIndexReferencesTheWholeSharedQueryGrammar(): void
    {
        $this->registerRestRoutes();
        $this->registerSoftDeletingRoutes();

        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination', 'Trashed'],
            $this->referencedParameterNames($this->build()['/articles']['get']),
        );
    }

    /**
     * Test that a read of a model with no soft deletes is never offered the
     * visibility parameter, so a generated client is never handed a widening
     * the server is bound to discard.
     *
     * @return void
     */
    public function testReadsOfAModelWithoutSoftDeletesOmitVisibility(): void
    {
        $this->registerRestRoutes();

        $paths = $this->build();

        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedParameterNames($paths['/users']['get']),
        );
        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages'],
            $this->referencedParameterNames($paths['/users/{user}']['get']),
        );
    }

    /**
     * Test that a show references only what shapes and scopes a single record,
     * so the document never claims a single resource can be paged or filtered.
     *
     * @return void
     */
    public function testShowReferencesOnlyTheSingleRecordGrammar(): void
    {
        $this->registerSoftDeletingRoutes();

        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Trashed'],
            $this->referencedParameterNames($this->build()['/articles/{article}']['get']),
        );
    }

    /**
     * Test that a store and an update reference only what shapes the resource
     * they return.
     *
     * @return void
     */
    public function testWriteActionsReferenceOnlyTheShapingGrammar(): void
    {
        $this->registerRestRoutes();

        $paths    = $this->build();
        $expected = ['Fields', 'Counts', 'Sums', 'Averages'];

        self::assertSame($expected, $this->referencedParameterNames($paths['/users']['post']));
        self::assertSame($expected, $this->referencedParameterNames($paths['/users/{user}']['patch']));
    }

    /**
     * Test that a destroy references no query parameter, its empty body having
     * nothing to shape.
     *
     * @return void
     */
    public function testDestroyReferencesNoQueryParameter(): void
    {
        $this->registerRestRoutes();

        self::assertSame([], $this->referencedParameterNames($this->build()['/users/{user}']['delete']));
    }

    /**
     * Test that a non-resource operation references no query parameter, the
     * builder having no way to know whether the action reads the query at all.
     *
     * @return void
     */
    public function testNonResourceOperationReferencesNoQueryParameter(): void
    {
        $this->router()->get('plain', [PathPlainController::class, 'report']);

        self::assertSame([], $this->referencedParameterNames($this->build()['/plain']['get']));
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
        self::assertSame('The request succeeded.', $operation['responses']['200']['description']);
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

        /** @var \Illuminate\Config\Repository $config */
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

        self::assertSame([], $this->build('partner', AudienceResolver::POSTURE_ALLOWLIST));
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
            'The request succeeded and a resource was created.',
            $responses['201']['description'],
        );
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

        self::assertSame('The request succeeded with no content.', $response['description']);
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
     * Test that a non-resource action declaring a self-describing DTO as a
     * collection wraps the DTO fragment as the length-aware collection
     * envelope's inline item schema.
     *
     * @return void
     */
    public function testResponseSchemaDtoCollectionActionInlinesItemFragment(): void
    {
        $this->router()->get('widgets', [PathResponseSchemaController::class, 'widgets']);

        $schema = $this->build()['/widgets']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelopeFor(WidgetShape::schema()),
            $schema,
        );
        self::assertSame(WidgetShape::schema(), $schema['properties']['data']['items']);
        self::assertSame(['data', 'meta', 'links'], $schema['required']);
    }

    /**
     * Test that a non-resource action declaring several shapes embeds their
     * oneOf under the single envelope's data property.
     *
     * @return void
     */
    public function testResponseSchemaUnionActionEmbedsOneOf(): void
    {
        $this->router()->get('session', [PathResponseSchemaController::class, 'union']);

        $schema = $this->build()['/session']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(['oneOf' => [SignedInShape::schema(), PendingSignInShape::schema()]]),
            $schema,
        );
    }

    /**
     * Test that the documented envelope required-key sets exactly match the
     * keys the runtime response path emits for a single resource, a
     * length-aware collection, and a cursor collection, so the documentation
     * never drifts from the shape the toolkit actually returns.
     *
     * @return void
     */
    public function testDocumentedEnvelopeKeysMatchRuntimeEmission(): void
    {
        $user      = User::create(['name' => 'Runtime', 'email' => 'runtime@example.com']);
        $envelope  = new EnvelopeBuilder;
        $schemas   = $envelope->buildSchemas();
        $reference = '#/components/schemas/User';

        $single = (new UserResource($user))->response()->getData(true);

        self::assertSame(
            $envelope->singleEnvelope($reference)['required'],
            array_keys($single),
        );

        $lengthAware = (new ApiResourceCollection(
            new LengthAwarePaginator([$user], 1, 15, 1, ['path' => 'http://localhost/users']),
            UserResource::class,
        ))->response()->getData(true);

        self::assertSame(
            $envelope->collectionEnvelope($reference)['required'],
            array_keys($lengthAware),
        );
        self::assertSame(
            $schemas[EnvelopeBuilder::PAGINATION_META_SCHEMA_NAME]['required'],
            array_keys($lengthAware['meta']),
        );
        self::assertSame(
            $schemas[EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME]['required'],
            array_keys($lengthAware['links']),
        );

        $cursor = (new ApiResourceCollection(
            new CursorPaginator([$user], 15, null, ['path' => 'http://localhost/users']),
            UserResource::class,
        ))->response()->getData(true);

        self::assertSame(
            $envelope->cursorCollectionEnvelope($reference)['required'],
            array_keys($cursor),
        );
        self::assertSame(
            $schemas[EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME]['required'],
            array_keys($cursor['meta']),
        );
        self::assertSame(
            $schemas[EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME]['required'],
            array_keys($cursor['links']),
        );
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
        self::assertSame([], $this->build('partner', AudienceResolver::POSTURE_ALLOWLIST));
    }

    /**
     * Test that a non-resource read verb never carries a request body even when
     * its action declares one.
     *
     * @return void
     */
    public function testNonResourceReadVerbSuppressesDeclaredRequestBody(): void
    {
        $this->router()->get('readbody', [PathRequestBodyController::class, 'store']);

        self::assertArrayNotHasKey('requestBody', $this->build()['/readbody']['get']);
    }

    /**
     * Test that a resource read verb never carries a request body even when its
     * action declares one, while the collection response is still emitted.
     *
     * @return void
     */
    public function testResourceReadVerbSuppressesDeclaredRequestBody(): void
    {
        $this->router()->get('errors', [PathErrorController::class, 'index']);

        $operation = $this->build()['/errors']['get'];

        self::assertArrayNotHasKey('requestBody', $operation);
        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $operation['responses']['200']['content']['application/json']['schema']['anyOf'][0],
        );
    }

    /**
     * Test that a resource write verb carries the request body resolved from
     * the action's declared rules source.
     *
     * @return void
     */
    public function testResourceWriteVerbCarriesResolvedRequestBody(): void
    {
        $this->router()->post('errors', [PathErrorController::class, 'store']);

        $operation = $this->build()['/errors']['post'];

        self::assertArrayHasKey('requestBody', $operation);
        self::assertArrayHasKey(
            'title',
            $operation['requestBody']['content']['application/json']['schema']['properties'],
        );
    }

    /**
     * Test that the merged responses are ordered by ascending status, with the
     * success entry first and the error statuses following in numeric order.
     *
     * @return void
     */
    public function testMergedResponsesAreOrderedByStatus(): void
    {
        $this->registerRestRoutes('auth');

        self::assertSame(
            [201, 401, 403, 409, 422, 500],
            array_keys($this->build()['/users']['post']['responses']),
        );
    }

    /**
     * Test that two thrown exceptions sharing a status merge their examples
     * under one response carrying the derived multi-word phrase.
     *
     * @return void
     */
    public function testThrownExceptionsSharingStatusMergeExamples(): void
    {
        $this->router()->get('errors/{error}', [PathErrorController::class, 'show']);

        $responses = $this->build()['/errors/{error}']['get']['responses'];

        self::assertArrayHasKey('503', $responses);
        self::assertSame('Service unavailable.', $responses['503']['description']);
        self::assertSame(
            ['ServiceUnavailableException', 'MaintenanceModeException'],
            array_keys($responses['503']['content']['application/json']['examples']),
        );
    }

    /**
     * Test that a thrown status coinciding with a known baseline phrase keeps
     * that phrase even when the action's baseline set omits the status.
     *
     * @return void
     */
    public function testThrownBaselineStatusKeepsBaselinePhrase(): void
    {
        $this->router()->get('errors/{error}', [PathErrorController::class, 'show']);

        $responses = $this->build()['/errors/{error}']['get']['responses'];

        self::assertArrayHasKey('422', $responses);
        self::assertSame('The request payload failed validation.', $responses['422']['description']);
    }

    /**
     * Test that a throws tag resolving to a class outside the API exception
     * base is ignored even when it declares an HTTP status constant.
     *
     * @return void
     */
    public function testNonApiThrowsAreIgnored(): void
    {
        $this->router()->put('errors/{error}', [PathErrorController::class, 'update'])->middleware('auth');

        $statuses = array_keys($this->build()['/errors/{error}']['put']['responses']);

        self::assertSame([200, 401, 403, 404, 422, 500], $statuses);
    }

    /**
     * Test that a route listing an excluded verb before a documentable one
     * still emits the documentable verb rather than stopping at the excluded
     * one.
     *
     * @return void
     */
    public function testExcludedVerbBeforeDocumentableStillEmits(): void
    {
        $this->router()->match(['HEAD', 'GET'], 'ping', [PathPlainController::class, 'report']);

        $paths = $this->build();

        self::assertArrayHasKey('/ping', $paths);
        self::assertSame(['get'], array_keys($paths['/ping']));
    }

    /**
     * Test that a route with several path segments emits one parameter per
     * segment rather than only the first.
     *
     * @return void
     */
    public function testEveryPathParameterIsEmitted(): void
    {
        $this->router()->get('teams/{team}/members/{member}', [PathPlainController::class, 'report']);

        $parameters = $this->build()['/teams/{team}/members/{member}']['get']['parameters'];

        self::assertSame(['team', 'member'], array_column($parameters, 'name'));
    }

    /**
     * Test that a controller class carrying a leading namespace separator is
     * still matched against the blocklist and excluded.
     *
     * @return void
     */
    public function testLeadingSeparatorControllerIsStillExcluded(): void
    {
        assert($this->app !== null);

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app->make('config');

        $config->set(
            'api-toolkit.openapi.exclude.namespaces',
            ['Tests\Fixtures\OpenApi\Vendor'],
        );

        $this->router()->get('vendor', ['\\' . PathVendorController::class, 'index']);

        self::assertArrayNotHasKey('/vendor', $this->build());
    }

    /**
     * Test that an authorized controller declaring no resource attribute, whose
     * model resolution fails, still emits an operation carrying the
     * undocumented success envelope and only the unconditional 500.
     *
     * @return void
     */
    public function testControllerWithoutResourceAttributeFallsBackToUndocumentedOperation(): void
    {
        $this->router()->get('drafts', [PathAttributelessController::class, 'index']);

        $operation = $this->build()['/drafts']['get'];

        self::assertSame([200, 500], array_keys($operation['responses']));
        self::assertSame('The request succeeded.', $operation['responses'][200]['description']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses'][200]['content']['application/json']['schema'],
        );
        self::assertArrayNotHasKey('parameters', $operation);
    }

    /**
     * Test that a route naming an action the controller never declares still
     * emits its resource operation, carrying the baseline error statuses alone.
     *
     * @return void
     */
    public function testRoutedActionAbsentFromControllerCarriesBaselineErrorsOnly(): void
    {
        $this->router()->delete('gaps/{gap}', [PathReflectionGapController::class, 'destroy'])->middleware('auth');

        $responses = $this->build()['/gaps/{gap}']['delete']['responses'];

        self::assertSame([204, 401, 403, 404, 500], array_keys($responses));
        self::assertSame('The resource was deleted.', $responses[204]['description']);
    }

    /**
     * Test that an action carrying no doc comment, and so no documented throws,
     * emits the baseline error statuses alone.
     *
     * @return void
     */
    public function testActionWithoutDocCommentCarriesBaselineErrorsOnly(): void
    {
        $this->router()->get('gaps', [PathReflectionGapController::class, 'index'])->middleware('auth');

        $responses = $this->build()['/gaps']['get']['responses'];

        self::assertSame([200, 401, 403, 500], array_keys($responses));
        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $responses[200]['content']['application/json']['schema']['anyOf'][0],
        );
    }

    /**
     * Test that a resource route without auth middleware omits the
     * unauthenticated status while its authorization check keeps forbidden.
     *
     * @return void
     */
    public function testResourceRouteWithoutAuthOmitsUnauthenticated(): void
    {
        $this->registerRestRoutes();

        self::assertSame([200, 403, 500], array_keys($this->build()['/users']['get']['responses']));
    }

    /**
     * Test that a non-resource route documents the unauthenticated status only
     * behind auth middleware.
     *
     * @return void
     */
    public function testNonResourceRouteDocumentsUnauthenticatedOnlyBehindAuth(): void
    {
        $this->router()->get('private', [PathPlainController::class, 'report'])->middleware('auth');
        $this->router()->get('public', [PathPlainController::class, 'index']);

        $paths = $this->build();

        self::assertSame([200, 401, 500], array_keys($paths['/private']['get']['responses']));
        self::assertSame('Unauthenticated.', $paths['/private']['get']['responses'][401]['description']);
        self::assertSame([200, 500], array_keys($paths['/public']['get']['responses']));
    }

    /**
     * Test that a throttled route documents the too-many-requests status under
     * its baseline phrase.
     *
     * @return void
     */
    public function testThrottledRouteDocumentsTooManyRequests(): void
    {
        $this->router()->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:60,1');

        $responses = $this->build()['/limited']['get']['responses'];

        self::assertSame([200, 429, 500], array_keys($responses));
        self::assertSame('Too many requests.', $responses[429]['description']);
        self::assertSame(
            ['$ref' => '#/components/schemas/ErrorEnvelope'],
            $responses[429]['content']['application/json']['schema'],
        );
    }

    /**
     * Test that an authorization attribute on a non-resource action documents
     * the forbidden status.
     *
     * @return void
     */
    public function testAuthorizeAttributeDocumentsForbidden(): void
    {
        $this->router()->get('report', [PathRouteFactsController::class, 'report']);

        self::assertSame([200, 403, 500], array_keys($this->build()['/report']['get']['responses']));
    }

    /**
     * Test that a non-resource POST declaring a request schema documents the
     * validation status, while one declaring no rules omits it.
     *
     * @return void
     */
    public function testNonResourceRequestBodyDocumentsValidationError(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);
        $this->router()->post('reports', [PathPlainController::class, 'report']);

        $paths = $this->build();

        self::assertSame([201, 422, 500], array_keys($paths['/articles']['post']['responses']));
        self::assertSame([200, 500], array_keys($paths['/reports']['post']['responses']));
    }

    /**
     * Test that a non-resource validation status follows the resolved request
     * body rather than the action name: a body-bearing action of another name
     * documents it, and a store declaring no rules does not.
     *
     * @return void
     */
    public function testNonResourceValidationErrorFollowsRequestBodyNotActionName(): void
    {
        $this->router()->post('imports', [PathRequestBodyController::class, 'both']);
        $this->router()->post('posts', [PathUnmappedController::class, 'store']);

        $paths = $this->build();

        self::assertSame([200, 422, 500], array_keys($paths['/imports']['post']['responses']));
        self::assertSame([201, 403, 500], array_keys($paths['/posts']['post']['responses']));
    }

    /**
     * Test that a non-resource action documents the ApiException subclasses its
     * throws tags name under their statuses with code examples, including a
     * status the enum lacks, while ignoring the Throwable and LogicException
     * tags beside them and an ApiException that reports no status.
     *
     * @return void
     */
    public function testNonResourceActionDocumentsThrownApiExceptions(): void
    {
        $this->router()->post('sign-in', [PathRouteFactsController::class, 'signIn']);

        $responses = $this->build()['/sign-in']['post']['responses'];

        self::assertSame([200, 401, 403, 419, 429, 500], array_keys($responses));
        self::assertSame('Unauthenticated.', $responses[401]['description']);
        self::assertSame('Token mismatch.', $responses[419]['description']);
        self::assertSame(
            ['TokenMismatchException' => ['value' => ['error' => ['status' => 419, 'code' => 10105]]]],
            $responses[419]['content']['application/json']['examples'],
        );
        self::assertSame(
            ['UnauthenticatedException' => ['value' => ['error' => ['status' => 401, 'code' => 10101]]]],
            $responses[401]['content']['application/json']['examples'],
        );
        self::assertSame(
            ['ForbiddenException' => ['value' => ['error' => ['status' => 403, 'code' => 10102]]]],
            $responses[403]['content']['application/json']['examples'],
        );
        self::assertSame(
            ['TooManyRequestsException' => ['value' => ['error' => ['status' => 429, 'code' => 10107]]]],
            $responses[429]['content']['application/json']['examples'],
        );
        self::assertArrayNotHasKey('examples', $responses[500]['content']['application/json']);
    }

    /**
     * Test that facts applied through a middleware group are resolved, and that
     * a group member removed with withoutMiddleware() is not documented.
     *
     * @return void
     */
    public function testMiddlewareGroupFactsAreResolved(): void
    {
        $this->router()->middlewareGroup('facts', ['auth', 'throttle:60,1']);
        $this->router()->get('grouped', [PathPlainController::class, 'report'])->middleware('facts');
        $this->router()->get('unthrottled', [PathPlainController::class, 'index'])->middleware('facts')->withoutMiddleware('throttle');

        $paths = $this->build();

        self::assertSame([200, 401, 429, 500], array_keys($paths['/grouped']['get']['responses']));
        self::assertSame([200, 401, 500], array_keys($paths['/unthrottled']['get']['responses']));
    }

    /**
     * Test that a closure route documents the route-fact statuses without any
     * throws-derived ones.
     *
     * @return void
     */
    public function testClosureRouteDocumentsRouteFactErrors(): void
    {
        $this->router()->get('items/{item}', static fn (): null => null)->middleware('auth');

        self::assertSame([200, 401, 404, 500], array_keys($this->build()['/items/{item}']['get']['responses']));
    }

    /**
     * Test that the headers an action declares are documented under its success
     * response, between its description and its content.
     *
     * @return void
     */
    public function testDeclaredHeadersAreDocumentedOnTheSuccessResponse(): void
    {
        $this->router()->post('sign-in', [PathResponseHeaderController::class, 'signIn']);

        $success = $this->build()['/sign-in']['post']['responses'][200];

        self::assertSame(['description', 'headers', 'content'], array_keys($success));
        self::assertSame('The request succeeded.', $success['description']);
        self::assertSame([
            'Set-Cookie' => [
                'description' => 'The refresh token, HttpOnly, Secure, SameSite=Strict.',
                'schema'      => ['type' => 'string', 'pattern' => '^refresh_token='],
            ],
            'X-Session-Id' => ['schema' => ['type' => 'string']],
        ], $success['headers']);
        self::assertSame(['schema' => $this->undocumentedEnvelope()], $success['content']['application/json']);
    }

    /**
     * Test that the headers a resource action declares follow the total-count
     * header the exporter emits on a collection.
     *
     * @return void
     */
    public function testDeclaredHeadersFollowTheExporterHeadersOnAResource(): void
    {
        $this->router()->get('users', [PathHeaderedUserController::class, 'index']);

        $success = $this->build()['/users']['get']['responses']['200'];

        self::assertSame(['description', 'headers', 'content'], array_keys($success));
        self::assertSame(
            [
                'Total-Count' => ['$ref' => '#/components/headers/Total-Count'],
                'Link'        => ['description' => 'The links to the neighbouring pages.', 'schema' => ['type' => 'string']],
            ],
            $success['headers'],
        );
    }

    /**
     * Test that a resource action answering with no content still documents the
     * headers it declares.
     *
     * @return void
     */
    public function testDeclaredHeadersAreDocumentedOnAnEmptyResourceResponse(): void
    {
        $this->router()->delete('users/{user}', [PathHeaderedUserController::class, 'destroy']);

        self::assertSame([
            'description' => 'The resource was deleted.',
            'headers'     => [
                'X-Deleted-At' => ['description' => 'When the resource was deleted.', 'schema' => ['type' => 'string', 'format' => 'date-time']],
            ],
        ], $this->build()['/users/{user}']['delete']['responses']['204']);
    }

    /**
     * Test that declaring a header the exporter emits itself, under any case,
     * fails the build rather than silently replacing it.
     *
     * @return void
     */
    public function testDeclaringAnExporterHeaderFailsTheBuild(): void
    {
        $this->router()->get('users', [PathHeaderCollisionController::class, 'index']);

        $this->expectException(ResponseHeaderCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The TOTAL-COUNT header declared for %s::index is emitted by the exporter and cannot be redeclared',
            PathHeaderCollisionController::class,
        ));

        $this->build();
    }

    /**
     * Test that an exception's declared headers, including those inherited from
     * its parent, are documented on its status's error response, while a status
     * whose exceptions declare none carries no headers.
     *
     * @return void
     */
    public function testThrownExceptionHeadersAreDocumentedOnTheErrorResponse(): void
    {
        $this->router()->post('sign-in', [PathResponseHeaderController::class, 'signIn']);

        $responses = $this->build()['/sign-in']['post']['responses'];

        self::assertSame(['description', 'headers', 'content'], array_keys($responses[429]));
        self::assertSame(
            ['Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']]],
            $responses[429]['headers'],
        );
        self::assertSame(['BurstLimitedException'], array_keys($responses[429]['content']['application/json']['examples']));
        self::assertSame(['description', 'content'], array_keys($responses[409]));
        self::assertSame(['description', 'content'], array_keys($responses[500]));
    }

    /**
     * Test that exceptions sharing a baseline status merge their declared
     * headers onto that status's response.
     *
     * @return void
     */
    public function testThrownExceptionHeadersMergeOntoABaselineStatus(): void
    {
        $this->router()->post('quota', [PathResponseHeaderController::class, 'quota'])->middleware('throttle:60,1');

        $response = $this->build()['/quota']['post']['responses'][429];

        self::assertSame('Too many requests.', $response['description']);
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($response['headers']),
        );
    }

    /**
     * Test that an operation declaring nothing, whose exceptions declare
     * nothing, documents no header anywhere.
     *
     * @return void
     */
    public function testUndeclaredOperationDocumentsNoHeader(): void
    {
        $this->router()->get('bare', [PathResponseHeaderController::class, 'bare']);

        $responses = $this->build()['/bare']['get']['responses'];

        self::assertSame([200, 409, 500], array_keys($responses));
        self::assertSame(['description' => 'The request succeeded.', 'content' => ['application/json' => ['schema' => $this->undocumentedEnvelope()]]], $responses[200]);
        self::assertStringNotContainsString('"headers"', (string) json_encode($responses));
    }

    /**
     * Test that a resource update declaring 204 documents a 204 with no body in
     * place of its 200, carrying the header its controller declares.
     *
     * @return void
     */
    public function testResourceUpdateDeclaringNoContentDocumentsNoBody(): void
    {
        $this->router()->put('users/{user}', [PathStatusUserController::class, 'update']);

        $responses = $this->build()['/users/{user}']['put']['responses'];

        self::assertSame([204, 403, 404, 422, 500], array_keys($responses));
        self::assertSame([
            'description' => 'No Content.',
            'headers'     => ['X-Request-Id' => ['schema' => ['type' => 'string']]],
        ], $responses[204]);
    }

    /**
     * Test that a non-REST action declaring 204 documents a 204 with no body in
     * place of its 200, carrying its own header before its controller's.
     *
     * @return void
     */
    public function testNonRestActionDeclaringNoContentDocumentsNoBody(): void
    {
        $this->router()->delete('sessions', [PathResponseStatusController::class, 'destroyAll']);

        $responses = $this->build()['/sessions']['delete']['responses'];

        self::assertSame([204, 500], array_keys($responses));
        self::assertSame([
            'description' => 'No Content.',
            'headers'     => [
                'Clear-Site-Data' => ['description' => 'The browsing data to clear.', 'schema' => ['type' => 'string']],
                'X-Request-Id'    => ['schema' => ['type' => 'string']],
            ],
        ], $responses[204]);
    }

    /**
     * Test that an action declaring 205 documents it with no body.
     *
     * @return void
     */
    public function testActionDeclaringResetContentDocumentsNoBody(): void
    {
        $this->router()->post('reset', [PathResponseStatusController::class, 'reset']);

        $responses = $this->build()['/reset']['post']['responses'];

        self::assertSame([205, 500], array_keys($responses));
        self::assertSame('Reset Content.', $responses[205]['description']);
        self::assertArrayNotHasKey('content', $responses[205]);
    }

    /**
     * Test that an action declaring 202 beside a response schema documents that
     * body under 202.
     *
     * @return void
     */
    public function testActionDeclaringAcceptedKeepsItsDeclaredBody(): void
    {
        $this->router()->post('jobs', [PathResponseStatusController::class, 'queue']);

        $responses = $this->build()['/jobs']['post']['responses'];

        self::assertSame([202, 500], array_keys($responses));
        self::assertSame([
            'description' => 'Accepted.',
            'headers'     => ['X-Request-Id' => ['schema' => ['type' => 'string']]],
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema())]],
        ], $responses[202]);
    }

    /**
     * Test that a non-resource store declaring 200 documents the undocumented
     * body under 200 in place of 201.
     *
     * @return void
     */
    public function testNonResourceStoreDeclaringOkKeepsTheUndocumentedBody(): void
    {
        $this->router()->post('items', [PathResponseStatusController::class, 'store']);

        $responses = $this->build()['/items']['post']['responses'];

        self::assertSame([200, 500], array_keys($responses));
        self::assertSame('OK.', $responses[200]['description']);
        self::assertSame(['schema' => $this->undocumentedEnvelope()], $responses[200]['content']['application/json']);
    }

    /**
     * Test that a destroy declaring 200 gains the body its response schema
     * declares, or the undocumented envelope on a resource route.
     *
     * @return void
     */
    public function testDestroyDeclaringOkGainsABody(): void
    {
        $this->router()->delete('items/{item}', [PathResponseStatusController::class, 'destroy']);
        $this->router()->delete('users/{user}', [PathStatusUserController::class, 'destroy']);

        $paths = $this->build();
        $item  = $paths['/items/{item}']['delete']['responses'];
        $user  = $paths['/users/{user}']['delete']['responses'];

        self::assertSame([200, 404, 500], array_keys($item));
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema()),
            $item[200]['content']['application/json']['schema'],
        );
        self::assertSame([200, 403, 404, 500], array_keys($user));
        self::assertSame([
            'description' => 'OK.',
            'headers'     => ['X-Request-Id' => ['schema' => ['type' => 'string']]],
            'content'     => ['application/json' => ['schema' => $this->undocumentedEnvelope()]],
        ], $user[200]);
    }

    /**
     * Test that a resource index declaring 206 keeps its collection body and
     * the total-count header the exporter emits, before its declared header.
     *
     * @return void
     */
    public function testResourceIndexDeclaringPartialContentKeepsTheCollection(): void
    {
        $this->router()->get('users', [PathStatusUserController::class, 'index']);

        $responses = $this->build()['/users']['get']['responses'];
        $envelope  = new EnvelopeBuilder;

        self::assertSame([206, 403, 500], array_keys($responses));
        self::assertSame('Partial Content.', $responses[206]['description']);
        self::assertSame(['Total-Count', 'X-Request-Id'], array_keys($responses[206]['headers']));
        self::assertSame(
            [
                'anyOf' => [
                    $envelope->collectionEnvelope('#/components/schemas/User'),
                    $envelope->cursorCollectionEnvelope('#/components/schemas/User'),
                ],
            ],
            $responses[206]['content']['application/json']['schema'],
        );
    }

    /**
     * Test that declaring the status an action answers with by default, or no
     * status at all, documents the action exactly as before.
     *
     * @return void
     */
    public function testDeclaringTheDefaultStatusOrNoneChangesNothing(): void
    {
        $this->router()->get('users/{user}', [PathStatusUserController::class, 'show']);
        $this->router()->post('users', [PathStatusUserController::class, 'store']);
        $this->router()->get('bare', [PathResponseStatusController::class, 'bare']);

        $paths    = $this->build();
        $header   = ['X-Request-Id' => ['schema' => ['type' => 'string']]];
        $envelope = (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User');

        self::assertSame([
            'description' => 'The requested resource.',
            'headers'     => $header,
            'content'     => ['application/json' => ['schema' => $envelope]],
        ], $paths['/users/{user}']['get']['responses'][200]);
        self::assertSame([
            'description' => 'The newly created resource.',
            'headers'     => $header,
            'content'     => ['application/json' => ['schema' => $envelope]],
        ], $paths['/users']['post']['responses'][201]);
        self::assertSame([
            'description' => 'The request succeeded.',
            'headers'     => $header,
            'content'     => ['application/json' => ['schema' => $this->undocumentedEnvelope()]],
        ], $paths['/bare']['get']['responses'][200]);
    }

    /**
     * Test that a body declared beside a no-content status fails the build on
     * a resource route.
     *
     * @return void
     */
    public function testNoContentStatusBesideABodyFailsTheBuild(): void
    {
        $this->router()->put('users/{user}', [PathStatusConflictController::class, 'update']);

        $this->expectException(ResponseStatusConflictException::class);
        $this->expectExceptionMessage(sprintf(
            'The 204 status declared for %s::update answers with no content and cannot carry a #[ResponseSchema] body',
            PathStatusConflictController::class,
        ));

        $this->build();
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
     * Register the full REST route set on the application router, applying the
     * given middleware to every route.
     *
     * @param  string  ...$middleware
     * @return void
     */
    private function registerRestRoutes(string ...$middleware): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index'])->middleware($middleware);
        $router->post('users', [PathFixtureController::class, 'store'])->middleware($middleware);
        $router->get('users/{user}', [PathFixtureController::class, 'show'])->middleware($middleware);
        $router->match(['PUT', 'PATCH'], 'users/{user}', [PathFixtureController::class, 'update'])->middleware($middleware);
        $router->delete('users/{user}', [PathFixtureController::class, 'destroy'])->middleware($middleware);
    }

    /**
     * Register the read routes of a controller reading a model that soft
     * deletes.
     *
     * @return void
     */
    private function registerSoftDeletingRoutes(): void
    {
        $router = $this->router();

        $router->get('articles', [PathArticleController::class, 'index']);
        $router->get('articles/{article}', [PathArticleController::class, 'show']);
    }

    /**
     * Build the paths object for the given audience and posture.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @return array<string, array<string, mixed>>
     */
    private function build(string $audience = 'public', string $posture = AudienceResolver::POSTURE_BLOCKLIST): array
    {
        return $this->builder()->build($audience, $posture);
    }

    /**
     * List the shared query-parameter component names the operation references,
     * in the order it carries them.
     *
     * @param  array<string, mixed>  $operation
     * @return array<int, string>
     */
    private function referencedParameterNames(array $operation): array
    {
        $names      = [];
        $parameters = $operation['parameters'] ?? [];

        if (!is_array($parameters)) {
            return [];
        }

        foreach ($parameters as $parameter) {

            $reference = is_array($parameter) ? $parameter['$ref'] ?? null : null;

            if (!is_string($reference)) {
                continue;
            }

            $names[] = str_replace('#/components/parameters/', '', $reference);
        }

        return $names;
    }

    /**
     * Reduce a parameter list to its path parameters.
     *
     * @param  array<int, array<string, mixed>>  $parameters
     * @return array<int, array<string, mixed>>
     */
    private function pathParametersIn(array $parameters): array
    {
        return array_values(array_filter(
            $parameters,
            static fn (array $parameter): bool => ($parameter['in'] ?? null) === 'path',
        ));
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
        $catalogue->method('getResourceMap')->willReturn([
            User::class    => UserResource::class,
            Article::class => ArticleResource::class,
        ]);

        return new PathBuilder(
            $this->router(),
            $catalogue,
            new AudienceResolver,
            new DocumentableRouteFilter,
            new EnvelopeBuilder,
            new TagResolver,
            new ResponseSchemaResolver($catalogue, new EnvelopeBuilder),
            new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder)),
            new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($this->router())),
            new QueryParameterBuilder($catalogue),
            new RouteMiddlewareResolver($this->router()),
            new ThrowsErrorResolver(new ResponseHeaderResolver),
            new ResponseHeaderResolver,
            new ResponseStatusResolver,
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

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app->make('config');

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
        $config->set('auth.guards.web', ['driver' => 'session']);
        $config->set('auth.guards.sanctum', ['driver' => 'sanctum']);
        $config->set('auth.guards.admin', ['driver' => 'basic']);
    }
}
