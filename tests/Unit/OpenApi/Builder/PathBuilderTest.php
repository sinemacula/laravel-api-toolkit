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
use SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
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
use Tests\Fixtures\OpenApi\PathDeclaredBodyUserController;
use Tests\Fixtures\OpenApi\PathDeclaredResourceUserController;
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
     * Test that REST routes yield path templates and others contribute nothing.
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
     * Test that verbs of the same URI share one path item.
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
     * Test that the index offers both pagination shapes and the count header.
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

        self::assertIsArray($response);
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

        self::assertIsArray($response);
        self::assertArrayHasKey('201', $response);
        self::assertSame('The newly created resource.', $response['201']['description']);
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
            $response['201']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that PUT and PATCH map to the same update operation.
     *
     * @return void
     */
    public function testUpdateMapsBothPutAndPatch(): void
    {
        $this->registerRestRoutes();

        $item = $this->build()['/users/{user}'];

        self::assertSame($item['put'], $item['patch']);
        self::assertIsArray($item['put']['responses']);
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

        self::assertIsArray($response);
        self::assertArrayHasKey('204', $response);
        self::assertSame('The resource was deleted.', $response['204']['description']);
        self::assertArrayNotHasKey('content', $response['204']);
    }

    /**
     * Test that the index carries only the baseline error statuses that apply.
     *
     * @return void
     */
    public function testIndexCarriesCollectionBaselineErrors(): void
    {
        $this->registerRestRoutes('auth');

        $responses = $this->build()['/users']['get']['responses'];

        self::assertIsArray($responses);

        foreach (['401', '403', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('404', $responses);
        self::assertArrayNotHasKey('422', $responses);
    }

    /**
     * Test that a show carries not-found but omits the validation status.
     *
     * @return void
     */
    public function testShowCarriesResourceBaselineWithNotFound(): void
    {
        $this->registerRestRoutes('auth');

        $responses = $this->build()['/users/{user}']['get']['responses'];

        self::assertIsArray($responses);

        foreach (['401', '403', '404', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('422', $responses);
    }

    /**
     * Test that a create carries the validation status but omits not-found.
     *
     * @return void
     */
    public function testStoreCarriesValidationBaseline(): void
    {
        $this->registerRestRoutes('auth');

        $responses = $this->build()['/users']['post']['responses'];

        self::assertIsArray($responses);

        foreach (['401', '403', '422', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('404', $responses);
    }

    /**
     * Test that a destroy carries no-content and omits the validation status.
     *
     * @return void
     */
    public function testDestroyCarriesBaselineErrorsAlongsideNoContent(): void
    {
        $this->registerRestRoutes('auth');

        $responses = $this->build()['/users/{user}']['delete']['responses'];

        self::assertIsArray($responses);

        foreach (['204', '401', '403', '404', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        self::assertArrayNotHasKey('422', $responses);
    }

    /**
     * Test that a baseline-only status references the shared error envelope.
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
        self::assertIsArray($response['content']['application/json']);
        self::assertArrayNotHasKey('examples', $response['content']['application/json']);
    }

    /**
     * Test that a thrown exception on a baseline status merges into it.
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
        self::assertIsArray($media['examples']);
        self::assertArrayHasKey('NotFoundException', $media['examples']);
        self::assertSame(
            ['status' => 404, 'code' => 10103],
            $media['examples']['NotFoundException']['value']['error'],
        );
    }

    /**
     * Test that a non-baseline thrown status surfaces with a code example.
     *
     * @return void
     */
    public function testThrownExceptionSurfacesUnderItsOwnStatus(): void
    {
        $this->registerRestRoutes();

        $responses = $this->build()['/users']['post']['responses'];

        self::assertIsArray($responses);
        self::assertArrayHasKey('409', $responses);
        self::assertSame('Conflict.', $responses['409']['description']);
        self::assertSame(
            ['status' => 409, 'code' => 10108],
            $responses['409']['content']['application/json']['examples']['ConflictException']['value']['error'],
        );
    }

    /**
     * Test that merged responses carry exactly one entry per status.
     *
     * @return void
     */
    public function testResponsesHaveExactlyOneEntryPerStatus(): void
    {
        $this->registerRestRoutes('auth');

        $statuses = array_keys($this->responsesOf('/users', 'post'));

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
     * Test that path parameters lead the parameter list.
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
        self::assertIsArray($parameters);
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
     * Test that an index references the whole shared query grammar.
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
     * Test that a model without soft deletes gets no visibility parameter.
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
     * Test that a show references only single-record query parameters.
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
     * Test that a store and an update reference only resource shaping.
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
     * Test that a destroy references no query parameter.
     *
     * @return void
     */
    public function testDestroyReferencesNoQueryParameter(): void
    {
        $this->registerRestRoutes();

        self::assertSame([], $this->referencedParameterNames($this->build()['/users/{user}']['delete']));
    }

    /**
     * Test that a non-resource operation references no query parameter.
     *
     * @return void
     */
    public function testNonResourceOperationReferencesNoQueryParameter(): void
    {
        $this->router()->get('plain', [PathPlainController::class, 'report']);

        self::assertSame([], $this->referencedParameterNames($this->build()['/plain']['get']));
    }

    /**
     * Test that an optional route parameter renders as required.
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
     * Test that a plain controller route emits an undocumented operation.
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
     * Test that an invokable controller emits an undocumented operation.
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
        self::assertIsArray($operation['responses']);
        self::assertArrayNotHasKey('401', $operation['responses']);
    }

    /**
     * Test that a non-REST action on an authorized controller is undocumented.
     *
     * @return void
     */
    public function testNonRestActionEmitsUndocumentedOperation(): void
    {
        $this->router()->get('users/export', [PathFixtureController::class, 'export']);

        $operation = $this->build()['/users/export']['get'];

        self::assertIsArray($operation['responses']);
        self::assertArrayHasKey('200', $operation['responses']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a closure route emits an undocumented operation.
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
     * Test that a closure scoped out with the macro is omitted.
     *
     * @return void
     */
    public function testClosureExcludedViaMacroIsOmitted(): void
    {
        $this->router()->get('secret', static fn (): null => null)->undocumented(); // @phpstan-ignore method.notFound

        self::assertArrayNotHasKey('/secret', $this->build());
    }

    /**
     * Test that a method-level #[Tag] beats the class-level one.
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
     * Test that a route excluded from one audience stays in another.
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
     * Test that a blocklisted controller namespace contributes no path.
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
     * Test that a controller without a registered resource is undocumented.
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
     * Test that a non-resource store emits 201 with the undocumented envelope.
     *
     * @return void
     */
    public function testNonResourceStoreEmitsCreatedStatus(): void
    {
        $this->router()->post('posts', [PathUnmappedController::class, 'store']);

        $responses = $this->build()['/posts']['post']['responses'];

        self::assertIsArray($responses);
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
        self::assertIsArray($response);
        self::assertArrayNotHasKey('content', $response);
    }

    /**
     * Test that a declared registered resource emits its single envelope.
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
     * Test that a declared resource collection emits the collection envelope.
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
     * Test that a declared DTO inlines its schema under the data property.
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
     * Test that a declared DTO collection inlines the DTO as the item schema.
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
     * Test that several declared shapes embed a oneOf under data.
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
     * Test that a resource store declaring a union documents it under 201.
     *
     * @return void
     */
    public function testResourceStoreDeclaringAUnionDocumentsIt(): void
    {
        $this->router()->post('users', [PathDeclaredBodyUserController::class, 'store']);

        $operation = $this->build()['/users']['post'];

        self::assertSame(['User'], $operation['tags']);
        self::assertIsArray($operation);
        self::assertArrayNotHasKey('parameters', $operation);
        self::assertIsArray($operation);
        self::assertArrayHasKey('requestBody', $operation);
        self::assertSame([201, 403, 422, 429, 500], array_keys($operation['responses']));
        self::assertSame([
            'description' => 'The request succeeded and a resource was created.',
            'headers'     => ['Set-Cookie' => ['description' => 'The refresh token.', 'schema' => ['type' => 'string']]],
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(['oneOf' => [SignedInShape::schema(), PendingSignInShape::schema()]])]],
        ], $operation['responses'][201]);
    }

    /**
     * Test that a resource update declaring a shape documents it under 200.
     *
     * @return void
     */
    public function testResourceUpdateDeclaringAShapeDocumentsIt(): void
    {
        $this->router()->put('users/{user}', [PathDeclaredBodyUserController::class, 'update']);

        $operation = $this->build()['/users/{user}']['put'];

        self::assertSame(['User'], $operation['tags']);
        self::assertSame([['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], $operation['parameters']);
        self::assertIsArray($operation);
        self::assertArrayNotHasKey('requestBody', $operation);
        self::assertIsArray($operation['responses']);
        self::assertSame([200, 403, 404, 422, 500], array_keys($operation['responses']));
        self::assertSame([
            'description' => 'The request succeeded.',
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(SignedInShape::schema())]],
        ], $operation['responses'][200]);
    }

    /**
     * Test that a resource show declaring a shape documents it under 200.
     *
     * @return void
     */
    public function testResourceShowDeclaringAShapeDocumentsIt(): void
    {
        $this->router()->get('users/{user}', [PathDeclaredBodyUserController::class, 'show']);

        $operation = $this->build()['/users/{user}']['get'];

        self::assertSame([], $this->referencedParameterNames($operation));
        self::assertIsArray($operation['responses']);
        self::assertSame([200, 403, 404, 500], array_keys($operation['responses']));
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema()),
            $operation['responses'][200]['content']['application/json']['schema'],
        );
    }

    /**
     * Test that an index and destroy declaring a status use that status.
     *
     * @return void
     */
    public function testResourceActionsDeclaringAShapeAndAStatusAnswerUnderIt(): void
    {
        $this->router()->get('users', [PathDeclaredBodyUserController::class, 'index']);
        $this->router()->delete('users/{user}', [PathDeclaredBodyUserController::class, 'destroy']);

        $paths    = $this->build();
        $envelope = new EnvelopeBuilder;

        self::assertIsArray($paths['/users']['get']);
        self::assertArrayNotHasKey('parameters', $paths['/users']['get']);
        self::assertIsArray($paths['/users']['get']['responses']);
        self::assertSame([206, 403, 500], array_keys($paths['/users']['get']['responses']));
        self::assertSame([
            'description' => 'Partial Content.',
            'content'     => ['application/json' => ['schema' => $envelope->collectionEnvelopeFor(WidgetShape::schema())]],
        ], $paths['/users']['get']['responses'][206]);
        self::assertIsArray($paths['/users/{user}']['delete']['responses']);
        self::assertSame([200, 403, 404, 500], array_keys($paths['/users/{user}']['delete']['responses']));
        self::assertSame([
            'description' => 'OK.',
            'content'     => ['application/json' => ['schema' => $envelope->singleEnvelopeFor(WidgetShape::schema())]],
        ], $paths['/users/{user}']['delete']['responses'][200]);
    }

    /**
     * Test that undeclared actions keep the resource body beside declared ones.
     *
     * @return void
     */
    public function testResourceActionWithoutAResponseSchemaKeepsTheResource(): void
    {
        $this->router()->post('users', [PathFixtureController::class, 'store']);
        $this->router()->put('users/{user}', [PathDeclaredBodyUserController::class, 'update']);

        $paths     = $this->build();
        $operation = $paths['/users']['post'];

        self::assertSame(['User'], $operation['tags']);
        self::assertSame(['Fields', 'Counts', 'Sums', 'Averages'], $this->referencedParameterNames($operation));
        self::assertIsArray($operation['responses']);
        self::assertSame([201, 403, 409, 422, 500], array_keys($operation['responses']));
        self::assertSame([
            'description' => 'The newly created resource.',
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User')]],
        ], $operation['responses'][201]);
        self::assertSame([], $this->referencedParameterNames($paths['/users/{user}']['put']));
    }

    /**
     * Test that an index declaring its own collection matches declaring none.
     *
     * @return void
     */
    public function testResourceIndexDeclaringItsOwnCollectionKeepsTheResource(): void
    {
        $this->router()->get('users', [PathDeclaredResourceUserController::class, 'index']);
        $this->router()->get('members', [PathFixtureController::class, 'index']);

        $paths    = $this->build();
        $envelope = new EnvelopeBuilder;

        $operation = $paths['/users']['get'];

        self::assertIsArray($operation);

        self::assertSame($paths['/members']['get'], $operation);
        self::assertSame(['User'], $operation['tags']);
        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedParameterNames($operation),
        );
        self::assertSame(
            ['Total-Count' => ['$ref' => '#/components/headers/Total-Count']],
            $operation['responses']['200']['headers'],
        );
        self::assertSame(
            [
                'anyOf' => [
                    $envelope->collectionEnvelope('#/components/schemas/User'),
                    $envelope->cursorCollectionEnvelope('#/components/schemas/User'),
                ],
            ],
            $operation['responses']['200']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a show declaring its own resource keeps the resource body.
     *
     * @return void
     */
    public function testResourceShowDeclaringItsOwnResourceKeepsTheResource(): void
    {
        $this->router()->get('users/{user}', [PathDeclaredResourceUserController::class, 'show']);

        $operation = $this->build()['/users/{user}']['get'];

        self::assertIsArray($operation);

        $responses = $operation['responses'];

        self::assertIsArray($responses);

        self::assertSame(['User'], $operation['tags']);
        self::assertSame(['Fields', 'Counts', 'Sums', 'Averages'], $this->referencedParameterNames($operation));
        self::assertSame([200, 403, 404, 500], array_keys($responses));
        self::assertSame([
            'description' => 'The requested resource.',
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User')]],
        ], $operation['responses'][200]);
    }

    /**
     * Test that declaring another shape or resource drops query parameters.
     *
     * @return void
     */
    public function testResourceActionsDeclaringAnotherResourceOrShapeDocumentIt(): void
    {
        $this->router()->post('users', [PathDeclaredResourceUserController::class, 'store']);
        $this->router()->put('users/{user}', [PathDeclaredResourceUserController::class, 'update']);

        $paths    = $this->build();
        $envelope = new EnvelopeBuilder;

        $store = $paths['/users']['post'];

        self::assertIsArray($store);

        $update = $paths['/users/{user}']['put'];

        self::assertIsArray($update);

        self::assertArrayNotHasKey('parameters', $store);
        self::assertSame([
            'description' => 'The request succeeded and a resource was created.',
            'content'     => ['application/json' => ['schema' => $envelope->collectionEnvelope('#/components/schemas/User')]],
        ], $store['responses'][201]);
        self::assertSame([], $this->referencedParameterNames($update));
        self::assertSame([
            'description' => 'The request succeeded.',
            'content'     => ['application/json' => ['schema' => $envelope->singleEnvelope('#/components/schemas/Article')]],
        ], $update['responses'][200]);
    }

    /**
     * Test that envelope required keys match the runtime response keys.
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

        self::assertIsArray($single);
        self::assertSame(
            $envelope->singleEnvelope($reference)['required'],
            array_keys($single),
        );

        $lengthAware = (new ApiResourceCollection(
            new LengthAwarePaginator([$user], 1, 15, 1, ['path' => 'http://localhost/users']),
            UserResource::class,
        ))->response()->getData(true);

        self::assertIsArray($lengthAware);
        self::assertSame(
            $envelope->collectionEnvelope($reference)['required'],
            array_keys($lengthAware),
        );
        self::assertIsArray($lengthAware['meta']);
        self::assertSame(
            $schemas[EnvelopeBuilder::PAGINATION_META_SCHEMA_NAME]['required'],
            array_keys($lengthAware['meta']),
        );
        self::assertIsArray($lengthAware['links']);
        self::assertSame(
            $schemas[EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME]['required'],
            array_keys($lengthAware['links']),
        );

        $cursor = (new ApiResourceCollection(
            new CursorPaginator([$user], 15, null, ['path' => 'http://localhost/users']),
            UserResource::class,
        ))->response()->getData(true);

        self::assertIsArray($cursor);
        self::assertSame(
            $envelope->cursorCollectionEnvelope($reference)['required'],
            array_keys($cursor),
        );
        self::assertIsArray($cursor['meta']);
        self::assertSame(
            $schemas[EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME]['required'],
            array_keys($cursor['meta']),
        );
        self::assertIsArray($cursor['links']);
        self::assertSame(
            $schemas[EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME]['required'],
            array_keys($cursor['links']),
        );
    }

    /**
     * Test that an action without a directive emits the undocumented envelope.
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
     * Test that a POST carries a requestBody from the directive rules source.
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
     * Test that a PUT/PATCH discovers its requestBody from a type hint.
     *
     * @return void
     */
    public function testUpdateOperationCarriesRequestBodyFromTypeHint(): void
    {
        $this->router()->match(['PUT', 'PATCH'], 'articles/{article}', [PathRequestBodyController::class, 'update']);

        $operation = $this->build()['/articles/{article}']['put'];

        self::assertIsArray($operation);
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

        self::assertArrayNotHasKey('requestBody', $this->operation('/articles', 'get'));
    }

    /**
     * Test that a DELETE operation carries no requestBody.
     *
     * @return void
     */
    public function testDeleteOperationHasNoRequestBody(): void
    {
        $this->router()->delete('articles/{article}', [PathRequestBodyController::class, 'destroy']);

        self::assertArrayNotHasKey('requestBody', $this->operation('/articles/{article}', 'delete'));
    }

    /**
     * Test that attaching a requestBody leaves the response body unchanged.
     *
     * @return void
     */
    public function testWriteOperationResponseBodyUnchanged(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $responses = $this->build()['/articles']['post']['responses'];

        self::assertIsArray($responses);
        self::assertArrayHasKey('201', $responses);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $responses['201']['content']['application/json']['schema'],
        );
    }

    /**
     * Test that a route without auth middleware carries empty security.
     *
     * @return void
     */
    public function testPublicOperationCarriesEmptySecurity(): void
    {
        $this->registerRestRoutes();

        self::assertSame([], $this->build()['/users']['get']['security']);
    }

    /**
     * Test that a single-guard route carries the guard's scheme.
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
     * Test that guards with different schemes emit separate requirements.
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
     * Test that guards sharing a scheme collapse to one requirement.
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
     * Test that a bare auth middleware resolves the default guard.
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
        // No route opts in, so even the framework's default routes are excluded
        self::assertSame([], $this->build('partner', AudienceResolver::POSTURE_ALLOWLIST));
    }

    /**
     * Test that a non-resource read verb never carries a request body.
     *
     * @return void
     */
    public function testNonResourceReadVerbSuppressesDeclaredRequestBody(): void
    {
        $this->router()->get('readbody', [PathRequestBodyController::class, 'store']);

        self::assertArrayNotHasKey('requestBody', $this->operation('/readbody', 'get'));
    }

    /**
     * Test that a resource read verb never carries a request body.
     *
     * @return void
     */
    public function testResourceReadVerbSuppressesDeclaredRequestBody(): void
    {
        $this->router()->get('errors', [PathErrorController::class, 'index']);

        $operation = $this->build()['/errors']['get'];

        self::assertIsArray($operation);
        self::assertArrayNotHasKey('requestBody', $operation);
        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $operation['responses']['200']['content']['application/json']['schema']['anyOf'][0],
        );
    }

    /**
     * Test that a resource write verb carries its resolved request body.
     *
     * @return void
     */
    public function testResourceWriteVerbCarriesResolvedRequestBody(): void
    {
        $this->router()->post('errors', [PathErrorController::class, 'store']);

        $operation = $this->build()['/errors']['post'];

        self::assertIsArray($operation);
        self::assertArrayHasKey('requestBody', $operation);
        self::assertArrayHasKey(
            'title',
            $operation['requestBody']['content']['application/json']['schema']['properties'],
        );
    }

    /**
     * Test that merged responses are ordered by ascending status.
     *
     * @return void
     */
    public function testMergedResponsesAreOrderedByStatus(): void
    {
        $this->registerRestRoutes('auth');

        self::assertSame(
            [201, 401, 403, 409, 422, 500],
            array_keys($this->responsesOf('/users', 'post')),
        );
    }

    /**
     * Test that thrown exceptions sharing a status merge their examples.
     *
     * @return void
     */
    public function testThrownExceptionsSharingStatusMergeExamples(): void
    {
        $this->router()->get('errors/{error}', [PathErrorController::class, 'show']);

        $responses = $this->build()['/errors/{error}']['get']['responses'];

        self::assertIsArray($responses);
        self::assertArrayHasKey('503', $responses);
        self::assertSame('Service unavailable.', $responses['503']['description']);
        self::assertSame(
            ['ServiceUnavailableException', 'MaintenanceModeException'],
            array_keys($responses['503']['content']['application/json']['examples']),
        );
    }

    /**
     * Test that a thrown baseline status keeps its known phrase.
     *
     * @return void
     */
    public function testThrownBaselineStatusKeepsBaselinePhrase(): void
    {
        $this->router()->get('errors/{error}', [PathErrorController::class, 'show']);

        $responses = $this->build()['/errors/{error}']['get']['responses'];

        self::assertIsArray($responses);
        self::assertArrayHasKey('422', $responses);
        self::assertSame('The request payload failed validation.', $responses['422']['description']);
    }

    /**
     * Test that a throws tag outside the API exception base is ignored.
     *
     * @return void
     */
    public function testNonApiThrowsAreIgnored(): void
    {
        $this->router()->put('errors/{error}', [PathErrorController::class, 'update'])->middleware('auth');

        $statuses = array_keys($this->responsesOf('/errors/{error}', 'put'));

        self::assertSame([200, 401, 403, 404, 422, 500], $statuses);
    }

    /**
     * Test that an excluded verb does not stop a later documentable one.
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
     * Test that each path segment emits its own parameter.
     *
     * @return void
     */
    public function testEveryPathParameterIsEmitted(): void
    {
        $this->router()->get('teams/{team}/members/{member}', [PathPlainController::class, 'report']);

        $parameters = $this->build()['/teams/{team}/members/{member}']['get']['parameters'];

        self::assertIsArray($parameters);
        self::assertSame(['team', 'member'], array_column($parameters, 'name'));
    }

    /**
     * Test that a leading namespace separator still matches the blocklist.
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
     * Test that a failed model resolution still emits an operation.
     *
     * @return void
     */
    public function testControllerWithoutResourceAttributeFallsBackToUndocumentedOperation(): void
    {
        $this->router()->get('drafts', [PathAttributelessController::class, 'index']);

        $operation = $this->build()['/drafts']['get'];

        self::assertIsArray($operation['responses']);
        self::assertSame([200, 500], array_keys($operation['responses']));
        self::assertSame('The request succeeded.', $operation['responses'][200]['description']);
        self::assertSame(
            $this->undocumentedEnvelope(),
            $operation['responses'][200]['content']['application/json']['schema'],
        );
        self::assertIsArray($operation);
        self::assertArrayNotHasKey('parameters', $operation);
    }

    /**
     * Test that an undeclared action carries only the baseline statuses.
     *
     * @return void
     */
    public function testRoutedActionAbsentFromControllerCarriesBaselineErrorsOnly(): void
    {
        $this->router()->delete('gaps/{gap}', [PathReflectionGapController::class, 'destroy'])->middleware('auth');

        $responses = $this->build()['/gaps/{gap}']['delete']['responses'];

        self::assertIsArray($responses);
        self::assertSame([204, 401, 403, 404, 500], array_keys($responses));
        self::assertSame('The resource was deleted.', $responses[204]['description']);
    }

    /**
     * Test that an action without a doc comment carries only the baseline.
     *
     * @return void
     */
    public function testActionWithoutDocCommentCarriesBaselineErrorsOnly(): void
    {
        $this->router()->get('gaps', [PathReflectionGapController::class, 'index'])->middleware('auth');

        $responses = $this->build()['/gaps']['get']['responses'];

        self::assertIsArray($responses);
        self::assertSame([200, 401, 403, 500], array_keys($responses));
        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $responses[200]['content']['application/json']['schema']['anyOf'][0],
        );
    }

    /**
     * Test that a resource route without auth omits unauthenticated.
     *
     * @return void
     */
    public function testResourceRouteWithoutAuthOmitsUnauthenticated(): void
    {
        $this->registerRestRoutes();

        self::assertSame([200, 403, 500], array_keys($this->responsesOf('/users', 'get')));
    }

    /**
     * Test that unauthenticated is documented only behind auth middleware.
     *
     * @return void
     */
    public function testNonResourceRouteDocumentsUnauthenticatedOnlyBehindAuth(): void
    {
        $this->router()->get('private', [PathPlainController::class, 'report'])->middleware('auth');
        $this->router()->get('public', [PathPlainController::class, 'index']);

        $paths = $this->build();

        self::assertIsArray($paths['/private']['get']['responses']);
        self::assertSame([200, 401, 500], array_keys($paths['/private']['get']['responses']));
        self::assertSame('Unauthenticated.', $paths['/private']['get']['responses'][401]['description']);
        self::assertIsArray($paths['/public']['get']['responses']);
        self::assertSame([200, 500], array_keys($paths['/public']['get']['responses']));
    }

    /**
     * Test that a throttled route documents the too-many-requests status.
     *
     * @return void
     */
    public function testThrottledRouteDocumentsTooManyRequests(): void
    {
        $this->router()->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:60,1');

        $responses = $this->build()['/limited']['get']['responses'];

        self::assertIsArray($responses);
        self::assertSame([200, 429, 500], array_keys($responses));
        self::assertSame('Too many requests.', $responses[429]['description']);
        self::assertSame(
            ['$ref' => '#/components/schemas/ErrorEnvelope'],
            $responses[429]['content']['application/json']['schema'],
        );
    }

    /**
     * Test that an authorization attribute documents the forbidden status.
     *
     * @return void
     */
    public function testAuthorizeAttributeDocumentsForbidden(): void
    {
        $this->router()->get('report', [PathRouteFactsController::class, 'report']);

        self::assertSame([200, 403, 500], array_keys($this->responsesOf('/report', 'get')));
    }

    /**
     * Test that a non-resource POST documents validation only with rules.
     *
     * @return void
     */
    public function testNonResourceRequestBodyDocumentsValidationError(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);
        $this->router()->post('reports', [PathPlainController::class, 'report']);

        $paths = $this->build();

        self::assertIsArray($paths['/articles']['post']['responses']);
        self::assertSame([201, 422, 500], array_keys($paths['/articles']['post']['responses']));
        self::assertIsArray($paths['/reports']['post']['responses']);
        self::assertSame([200, 500], array_keys($paths['/reports']['post']['responses']));
    }

    /**
     * Test that the validation status follows the resolved request body.
     *
     * @return void
     */
    public function testNonResourceValidationErrorFollowsRequestBodyNotActionName(): void
    {
        $this->router()->post('imports', [PathRequestBodyController::class, 'both']);
        $this->router()->post('posts', [PathUnmappedController::class, 'store']);

        $paths = $this->build();

        self::assertIsArray($paths['/imports']['post']['responses']);
        self::assertSame([200, 422, 500], array_keys($paths['/imports']['post']['responses']));
        self::assertIsArray($paths['/posts']['post']['responses']);
        self::assertSame([201, 403, 500], array_keys($paths['/posts']['post']['responses']));
    }

    /**
     * Test that a non-resource action documents its thrown ApiExceptions.
     *
     * @return void
     */
    public function testNonResourceActionDocumentsThrownApiExceptions(): void
    {
        $this->router()->post('sign-in', [PathRouteFactsController::class, 'signIn']);

        $responses = $this->build()['/sign-in']['post']['responses'];

        self::assertIsArray($responses);
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
        self::assertIsArray($responses[500]['content']['application/json']);
        self::assertArrayNotHasKey('examples', $responses[500]['content']['application/json']);
    }

    /**
     * Test that middleware group facts are resolved minus excluded members.
     *
     * @return void
     */
    public function testMiddlewareGroupFactsAreResolved(): void
    {
        $this->router()->middlewareGroup('facts', ['auth', 'throttle:60,1']);
        $this->router()->get('grouped', [PathPlainController::class, 'report'])->middleware('facts');
        $this->router()->get('unthrottled', [PathPlainController::class, 'index'])->middleware('facts')->withoutMiddleware('throttle');

        $paths = $this->build();

        self::assertIsArray($paths['/grouped']['get']['responses']);
        self::assertSame([200, 401, 429, 500], array_keys($paths['/grouped']['get']['responses']));
        self::assertIsArray($paths['/unthrottled']['get']['responses']);
        self::assertSame([200, 401, 500], array_keys($paths['/unthrottled']['get']['responses']));
    }

    /**
     * Test that a closure route documents only the route-fact statuses.
     *
     * @return void
     */
    public function testClosureRouteDocumentsRouteFactErrors(): void
    {
        $this->router()->get('items/{item}', static fn (): null => null)->middleware('auth');

        self::assertSame([200, 401, 404, 500], array_keys($this->responsesOf('/items/{item}', 'get')));
    }

    /**
     * Test that declared headers are documented on the success response.
     *
     * @return void
     */
    public function testDeclaredHeadersAreDocumentedOnTheSuccessResponse(): void
    {
        $this->router()->post('sign-in', [PathResponseHeaderController::class, 'signIn']);

        $success = $this->build()['/sign-in']['post']['responses'][200];

        self::assertIsArray($success);
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
     * Test that declared headers follow the exporter's total-count header.
     *
     * @return void
     */
    public function testDeclaredHeadersFollowTheExporterHeadersOnAResource(): void
    {
        $this->router()->get('users', [PathHeaderedUserController::class, 'index']);

        $success = $this->build()['/users']['get']['responses']['200'];

        self::assertIsArray($success);
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
     * Test that a no-content resource action still documents its headers.
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
     * Test that declaring an exporter-owned header fails the build.
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
     * Test that exception headers, inherited ones too, document on errors.
     *
     * @return void
     */
    public function testThrownExceptionHeadersAreDocumentedOnTheErrorResponse(): void
    {
        $this->router()->post('sign-in', [PathResponseHeaderController::class, 'signIn']);

        $responses = $this->build()['/sign-in']['post']['responses'];

        self::assertIsArray($responses[429]);
        self::assertSame(['description', 'headers', 'content'], array_keys($responses[429]));
        self::assertSame(
            ['Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']]],
            $responses[429]['headers'],
        );
        self::assertIsArray($responses[429]['content']['application/json']['examples']);
        self::assertSame(['BurstLimitedException'], array_keys($responses[429]['content']['application/json']['examples']));
        self::assertIsArray($responses[409]);
        self::assertSame(['description', 'content'], array_keys($responses[409]));
        self::assertIsArray($responses[500]);
        self::assertSame(['description', 'content'], array_keys($responses[500]));
    }

    /**
     * Test that exceptions sharing a status merge their declared headers.
     *
     * @return void
     */
    public function testThrownExceptionHeadersMergeOntoABaselineStatus(): void
    {
        $this->router()->post('quota', [PathResponseHeaderController::class, 'quota'])->middleware('throttle:60,1');

        $response = $this->build()['/quota']['post']['responses'][429];

        self::assertSame('Too many requests.', $response['description']);
        self::assertIsArray($response['headers']);
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($response['headers']),
        );
    }

    /**
     * Test that an operation declaring nothing documents no header.
     *
     * @return void
     */
    public function testUndeclaredOperationDocumentsNoHeader(): void
    {
        $this->router()->get('bare', [PathResponseHeaderController::class, 'bare']);

        $responses = $this->build()['/bare']['get']['responses'];

        self::assertIsArray($responses);
        self::assertSame([200, 409, 500], array_keys($responses));
        self::assertSame(['description' => 'The request succeeded.', 'content' => ['application/json' => ['schema' => $this->undocumentedEnvelope()]]], $responses[200]);
        self::assertStringNotContainsString('"headers"', (string) json_encode($responses));
    }

    /**
     * Test that a resource update declaring 204 documents a bodyless 204.
     *
     * @return void
     */
    public function testResourceUpdateDeclaringNoContentDocumentsNoBody(): void
    {
        $this->router()->put('users/{user}', [PathStatusUserController::class, 'update']);

        $responses = $this->build()['/users/{user}']['put']['responses'];

        self::assertIsArray($responses);
        self::assertSame([204, 403, 404, 422, 500], array_keys($responses));
        self::assertSame([
            'description' => 'No Content.',
            'headers'     => ['X-Request-Id' => ['schema' => ['type' => 'string']]],
        ], $responses[204]);
    }

    /**
     * Test that a non-REST action declaring 204 documents a bodyless 204.
     *
     * @return void
     */
    public function testNonRestActionDeclaringNoContentDocumentsNoBody(): void
    {
        $this->router()->delete('sessions', [PathResponseStatusController::class, 'destroyAll']);

        $responses = $this->build()['/sessions']['delete']['responses'];

        self::assertIsArray($responses);
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

        self::assertIsArray($responses);
        self::assertSame([205, 500], array_keys($responses));
        self::assertSame('Reset Content.', $responses[205]['description']);
        self::assertIsArray($responses[205]);
        self::assertArrayNotHasKey('content', $responses[205]);
    }

    /**
     * Test that an action declaring 202 documents its schema under 202.
     *
     * @return void
     */
    public function testActionDeclaringAcceptedKeepsItsDeclaredBody(): void
    {
        $this->router()->post('jobs', [PathResponseStatusController::class, 'queue']);

        $responses = $this->build()['/jobs']['post']['responses'];

        self::assertIsArray($responses);
        self::assertSame([202, 500], array_keys($responses));
        self::assertSame([
            'description' => 'Accepted.',
            'headers'     => ['X-Request-Id' => ['schema' => ['type' => 'string']]],
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema())]],
        ], $responses[202]);
    }

    /**
     * Test that a non-resource store declaring 200 documents it under 200.
     *
     * @return void
     */
    public function testNonResourceStoreDeclaringOkKeepsTheUndocumentedBody(): void
    {
        $this->router()->post('items', [PathResponseStatusController::class, 'store']);

        $responses = $this->build()['/items']['post']['responses'];

        self::assertIsArray($responses);
        self::assertSame([200, 500], array_keys($responses));
        self::assertSame('OK.', $responses[200]['description']);
        self::assertSame(['schema' => $this->undocumentedEnvelope()], $responses[200]['content']['application/json']);
    }

    /**
     * Test that a destroy declaring 200 gains its declared body.
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

        self::assertIsArray($item);
        self::assertSame([200, 404, 500], array_keys($item));
        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema()),
            $item[200]['content']['application/json']['schema'],
        );
        self::assertIsArray($user);
        self::assertSame([200, 403, 404, 500], array_keys($user));
        self::assertSame([
            'description' => 'OK.',
            'headers'     => ['X-Request-Id' => ['schema' => ['type' => 'string']]],
            'content'     => ['application/json' => ['schema' => $this->undocumentedEnvelope()]],
        ], $user[200]);
    }

    /**
     * Test that an index declaring 206 keeps its collection body and header.
     *
     * @return void
     */
    public function testResourceIndexDeclaringPartialContentKeepsTheCollection(): void
    {
        $this->router()->get('users', [PathStatusUserController::class, 'index']);

        $responses = $this->build()['/users']['get']['responses'];
        $envelope  = new EnvelopeBuilder;

        self::assertIsArray($responses);
        self::assertSame([206, 403, 500], array_keys($responses));
        self::assertSame('Partial Content.', $responses[206]['description']);
        self::assertIsArray($responses[206]['headers']);
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
     * Test that declaring the default status, or none, changes nothing.
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
     * Test that a body declared beside a no-content status fails the build.
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
     * Register the full REST route set with the given middleware.
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
     * Register the read routes of a controller over a soft-deleting model.
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
     * List the shared query-parameter names the operation references, in order.
     *
     * @param  mixed  $operation
     * @return array<int, string>
     */
    private function referencedParameterNames(mixed $operation): array
    {
        self::assertIsArray($operation);

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
     * @param  mixed  $parameters
     * @return list<mixed>
     */
    private function pathParametersIn(mixed $parameters): array
    {
        self::assertIsArray($parameters);

        return array_values(array_filter(
            $parameters,
            static fn (mixed $parameter): bool => is_array($parameter) && ($parameter['in'] ?? null) === 'path',
        ));
    }

    /**
     * Build the paths object and read one operation from it.
     *
     * @param  string  $path
     * @param  string  $method
     * @return array<array-key, mixed>
     */
    private function operation(string $path, string $method): array
    {
        $operation = $this->build()[$path][$method] ?? null;

        self::assertIsArray($operation);

        return $operation;
    }

    /**
     * Build the paths object and read one operation's responses.
     *
     * @param  string  $path
     * @param  string  $method
     * @return array<array-key, mixed>
     */
    private function responsesOf(string $path, string $method): array
    {
        $responses = $this->operation($path, $method)['responses'] ?? null;

        self::assertIsArray($responses);

        return $responses;
    }

    /**
     * Build a path builder whose catalogue maps the user model to its resource.
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
            new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder)),
            new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($this->router())),
            new QueryParameterBuilder($catalogue),
            new RouteMiddlewareResolver($this->router()),
            new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver),
            new SuccessResponseBuilder(
                new EnvelopeBuilder,
                new ResponseSchemaResolver($catalogue, new EnvelopeBuilder),
                new ResponseHeaderResolver,
                new ResponseStatusResolver,
            ),
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
