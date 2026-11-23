<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Http\Middleware\RequireIfMatch;
use SineMacula\ApiToolkit\Http\Middleware\RequireJsonBody;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use Tests\Concerns\WritesDocsDirectories;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ArticleRequestInput;
use Tests\Fixtures\OpenApi\PathArticleController;
use Tests\Fixtures\OpenApi\PathAttributelessController;
use Tests\Fixtures\OpenApi\PathBindingController;
use Tests\Fixtures\OpenApi\PathDeclaredBodyUserController;
use Tests\Fixtures\OpenApi\PathDeclaredResourceUserController;
use Tests\Fixtures\OpenApi\PathDocumentController;
use Tests\Fixtures\OpenApi\PathDocumentDeclarationsController;
use Tests\Fixtures\OpenApi\PathErrorController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathHeaderCollisionController;
use Tests\Fixtures\OpenApi\PathHeaderedUserController;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathOperationController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseExampleController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathStatusConflictController;
use Tests\Fixtures\OpenApi\PathStatusUserController;
use Tests\Fixtures\OpenApi\PathUnmappedController;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\CappedPageUserResource;
use Tests\Fixtures\Resources\DocumentResource;
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
    use WritesDocsDirectories;

    /** The description of the page size parameter */
    private const string LIMIT_DESCRIPTION = 'Page size: the maximum number of records to return per page. A request above the ceiling is rejected rather than reduced to it.';

    /** The reference to the widget shape's component */
    private const string WIDGET_REF = '#/components/schemas/WidgetShape';

    /** The reference to the signed-in shape's component */
    private const string SIGNED_IN_REF = '#/components/schemas/SignedInShape';

    /** The discriminated union of the signed-in and pending shapes */
    private const array SIGN_IN_UNION = [
        'oneOf' => [
            ['$ref' => self::SIGNED_IN_REF],
            ['$ref' => '#/components/schemas/PendingSignInShape'],
        ],
        'discriminator' => [
            'propertyName' => 'status',
            'mapping'      => [
                'signed_in' => self::SIGNED_IN_REF,
                'pending'   => '#/components/schemas/PendingSignInShape',
            ],
        ],
    ];

    /** @var array<class-string, class-string> The resource map the catalogue reports */
    private array $resourceMap = [
        User::class     => UserResource::class,
        Article::class  => ArticleResource::class,
        Document::class => DocumentResource::class,
    ];

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
     * Test that a resource operation documents the failures its query
     * parameters and body raise, and a delete documents none.
     *
     * @return void
     */
    public function testResourceOperationsDocumentTheFailuresTheirInputsRaise(): void
    {
        $this->registerRestRoutes();
        $this->router()->put('errors/{error}', [PathErrorController::class, 'update']);

        $paths = $this->build();

        self::assertSame([200, 403, 422, 500], $this->statusesOf($paths, '/users', 'get'));
        self::assertSame(['Error10106', 'Error10201'], $this->examplesOf($paths, '/users', 'get', 422));
        self::assertSame([200, 403, 404, 422, 500], $this->statusesOf($paths, '/users/{user}', 'get'));
        self::assertSame(['Error10106'], $this->examplesOf($paths, '/users/{user}', 'get', 422));
        self::assertSame([201, 403, 409, 422, 500], $this->statusesOf($paths, '/users', 'post'));
        self::assertSame(['Error10106'], $this->examplesOf($paths, '/users', 'post', 422));
        self::assertSame(['Error10106'], $this->examplesOf($paths, '/users/{user}', 'patch', 422));
        self::assertSame([204, 403, 404, 500], $this->statusesOf($paths, '/users/{user}', 'delete'));
        self::assertSame([200, 403, 404, 422, 500], $this->statusesOf($paths, '/errors/{error}', 'put'));
    }

    /**
     * Test that an index documents no query cost refusal while every cap is
     * disabled.
     *
     * @return void
     */
    public function testIndexDocumentsNoQueryCostRefusalWhileEveryCapIsDisabled(): void
    {
        config()->set('api-toolkit.query_cost', array_fill_keys(QueryCostLimits::NAMES, null));
        config()->set('api-toolkit.parser.max_limit', null);

        $this->registerRestRoutes();

        self::assertSame(['Error10106'], $this->examplesOf($this->build(), '/users', 'get', 422));
    }

    /**
     * Test that an operation outside the resource contract documents a
     * validation failure only for its body.
     *
     * @return void
     */
    public function testOperationOutsideTheResourceContractDocumentsOnlyItsBodyFailure(): void
    {
        $this->router()->get('plain', [PathPlainController::class, 'report']);
        $this->router()->get('widgets', [PathDeclaredBodyUserController::class, 'index']);
        $this->router()->post('users', [PathDeclaredResourceUserController::class, 'store']);
        $this->router()->put('users/{user}', [PathDeclaredResourceUserController::class, 'update']);

        $paths = $this->build();

        self::assertSame([200, 500], $this->statusesOf($paths, '/plain', 'get'));
        self::assertSame([206, 403, 500], $this->statusesOf($paths, '/widgets', 'get'));
        self::assertSame(['Error10106'], $this->examplesOf($paths, '/users', 'post', 422));
        self::assertSame(['Error10106'], $this->examplesOf($paths, '/users/{user}', 'put', 422));
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
     * Test that an operation carries no documentation keys when nothing is
     * declared.
     *
     * @return void
     */
    public function testOperationCarriesNoDocumentationWhenNothingIsDeclared(): void
    {
        $this->registerRestRoutes();

        $item = $this->build(['path-fixture.update' => 'Updates a user.']);

        self::assertSame(['tags', 'parameters', 'responses', 'security'], array_keys($this->operationOf($item, '/users/{user}', 'get')));
        self::assertSame(['tags', 'parameters', 'responses', 'security'], array_keys($this->operationOf($item, '/users/{user}', 'put')));
    }

    /**
     * Test that a declared summary is emitted whether ids are off or on.
     *
     * @return void
     */
    public function testDeclaredSummaryIsEmittedWithIdsOffOrOn(): void
    {
        $this->router()->get('widgets/{widget}', [PathOperationController::class, 'show']);
        $this->router()->get('widgets', [PathOperationController::class, 'index']);

        $off = $this->build();

        self::assertSame('Show a widget', $off['/widgets/{widget}']['get']['summary']);
        self::assertSame(['tags', 'summary', 'parameters', 'responses', 'security'], array_keys($this->operationOf($off, '/widgets/{widget}', 'get')));
        self::assertSame(['tags', 'responses', 'security'], array_keys($this->operationOf($off, '/widgets', 'get')));

        config()->set('api-toolkit.openapi.operation_ids', true);

        $on = $this->build();

        self::assertSame('Show a widget', $on['/widgets/{widget}']['get']['summary']);
        self::assertSame('widgets.showcase', $on['/widgets/{widget}']['get']['operationId']);
        self::assertSame(['tags', 'operationId', 'responses', 'security'], array_keys($this->operationOf($on, '/widgets', 'get')));
    }

    /**
     * Test that each verb carries its own operationId.
     *
     * @return void
     */
    public function testEachVerbCarriesItsOwnOperationId(): void
    {
        config()->set('api-toolkit.openapi.operation_ids', true);

        $this->registerRestRoutes();

        $paths = $this->build();

        self::assertSame('path-fixture.update', $this->operationOf($paths, '/users/{user}', 'put')['operationId']);
        self::assertSame('path-fixture.update.patch', $this->operationOf($paths, '/users/{user}', 'patch')['operationId']);
        self::assertSame('path-fixture.destroy', $this->operationOf($paths, '/users/{user}', 'delete')['operationId']);
    }

    /**
     * Test that an operation's description is read from the map by its id, and
     * absent when the map has none for it.
     *
     * @return void
     */
    public function testDescriptionIsReadByOperationId(): void
    {
        config()->set('api-toolkit.openapi.operation_ids', true);

        $this->registerRestRoutes();

        $paths = $this->build(['path-fixture.index' => 'Lists the users.', 'path-fixture.destroy' => '']);

        self::assertSame('Lists the users.', $paths['/users']['get']['description']);
        self::assertArrayNotHasKey('description', $this->operationOf($paths, '/users', 'post'));
        self::assertArrayNotHasKey('description', $this->operationOf($paths, '/users/{user}', 'delete'));
    }

    /**
     * Test that a later verb falls back to the primary description unless it
     * has its own, an empty one blanking it.
     *
     * @return void
     */
    public function testLaterVerbFallsBackToThePrimaryDescription(): void
    {
        config()->set('api-toolkit.openapi.operation_ids', true);

        $this->registerRestRoutes();

        $fallback = $this->build(['path-fixture.update' => 'Updates a user.']);
        $own      = $this->build(['path-fixture.update' => 'Updates a user.', 'path-fixture.update.patch' => 'Patches a user.']);
        $blank    = $this->build(['path-fixture.update' => 'Updates a user.', 'path-fixture.update.patch' => '']);

        self::assertSame('Updates a user.', $this->operationOf($fallback, '/users/{user}', 'put')['description']);
        self::assertSame('Updates a user.', $this->operationOf($fallback, '/users/{user}', 'patch')['description']);
        self::assertSame('Updates a user.', $this->operationOf($own, '/users/{user}', 'put')['description']);
        self::assertSame('Patches a user.', $this->operationOf($own, '/users/{user}', 'patch')['description']);
        self::assertSame('Updates a user.', $this->operationOf($blank, '/users/{user}', 'put')['description']);
        self::assertArrayNotHasKey('description', $this->operationOf($blank, '/users/{user}', 'patch'));
    }

    /**
     * Test that every documentation key sits between the tags and the
     * parameters.
     *
     * @return void
     */
    public function testDocumentationKeysSitBetweenTheTagsAndTheParameters(): void
    {
        config()->set('api-toolkit.openapi.operation_ids', true);

        $this->router()->match(['PUT', 'PATCH'], 'widgets/{widget}', [PathOperationController::class, 'update']);

        $operation = $this->operationOf($this->build(['path-operation.update' => 'Updates a widget.']), '/widgets/{widget}', 'patch');

        self::assertSame(['tags', 'summary', 'description', 'operationId', 'parameters', 'requestBody', 'responses', 'security'], array_keys($operation));
        self::assertSame(['PathOperation'], $operation['tags']);
        self::assertSame('Update a widget', $operation['summary']);
        self::assertSame('Updates a widget.', $operation['description']);
        self::assertSame('path-operation.update.patch', $operation['operationId']);
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
     * Test that a typed, constrained route's parameters reach the operation.
     *
     * @return void
     */
    public function testTypedPathParametersReachTheOperation(): void
    {
        $this->router()->get('users/{user}/tickets/{ticket}', [PathBindingController::class, 'userTicket'])->whereUuid('ticket');

        self::assertSame([
            ['name' => 'user', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
        ], $this->operation('/users/{user}/tickets/{ticket}', 'get')['parameters']);
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
     * Test that an index takes the shared query grammar and what its resource
     * accepts.
     *
     * @return void
     */
    public function testIndexTakesTheSharedGrammarAndWhatItsResourceAccepts(): void
    {
        $this->registerRestRoutes();
        $this->registerSoftDeletingRoutes();

        self::assertSame(
            ['Fields', 'Aggregates', 'order', 'Limit', 'Page', 'Cursor', 'Pagination', 'Trashed'],
            $this->referencedParameterNames($this->build()['/articles']['get']),
        );
    }

    /**
     * Test that an index over a resource with its own page size bounds states
     * them in an inline limit, and its other actions keep the shared grammar.
     *
     * @return void
     */
    public function testIndexOverALoweredResourceExportsItsOwnLimit(): void
    {
        $this->resourceMap[User::class] = CappedPageUserResource::class;

        $this->registerRestRoutes();

        $paths = $this->build();

        self::assertIsArray($paths['/users']['get']['parameters']);
        self::assertContains(
            [
                'name'        => 'limit',
                'in'          => 'query',
                'required'    => false,
                'description' => self::LIMIT_DESCRIPTION,
                'schema'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2, 'default' => 2],
            ],
            $paths['/users']['get']['parameters'],
        );
        self::assertSame(
            ['Fields', 'Aggregates', 'filters', 'order', 'limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedParameterNames($paths['/users']['get']),
        );
        self::assertSame(['Fields', 'Aggregates'], $this->referencedParameterNames($paths['/users/{user}']['get']));
    }

    /**
     * Test that an index over a resource with its own ceiling documents the
     * query cost refusal while every configured cap is disabled.
     *
     * @return void
     */
    public function testIndexOverALoweredResourceDocumentsTheQueryCostRefusal(): void
    {
        config()->set('api-toolkit.query_cost', array_fill_keys(QueryCostLimits::NAMES, null));
        config()->set('api-toolkit.parser.max_limit', null);

        $this->resourceMap[User::class] = CappedPageUserResource::class;

        $this->registerRestRoutes();

        self::assertSame(['Error10106', 'Error10201'], $this->examplesOf($this->build(), '/users', 'get', 422));
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
            ['Fields', 'Aggregates', 'filters', 'order', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedParameterNames($paths['/users']['get']),
        );
        self::assertSame(
            ['Fields', 'Aggregates'],
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
            ['Fields', 'Aggregates', 'Trashed'],
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
        $expected = ['Fields', 'Aggregates'];

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
     * Test that an optional route parameter exports a path item per form: the
     * bare one without parameters or an inferred 404, the full one requiring
     * the parameter, both otherwise alike.
     *
     * @return void
     */
    public function testOptionalParameterExportsAPathItemPerForm(): void
    {
        $this->router()->get('reports/{period?}', [PathPlainController::class, 'report'])->middleware('auth');

        $paths = $this->build();
        $bare  = $this->operationOf($paths, '/reports', 'get');
        $full  = $this->operationOf($paths, '/reports/{period}', 'get');

        self::assertSame(['/reports', '/reports/{period}'], array_keys($paths));
        self::assertArrayNotHasKey('parameters', $bare);
        self::assertSame([['name' => 'period', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], $full['parameters']);
        self::assertSame([200, 401, 500], $this->statusesOf($paths, '/reports', 'get'));
        self::assertSame([200, 401, 404, 500], $this->statusesOf($paths, '/reports/{period}', 'get'));

        unset($full['parameters'], $full['responses'][404]);

        self::assertSame($bare, $full);
    }

    /**
     * Test that the bare form keeps a 404 when a default still binds the
     * omitted parameter, or the action declares one.
     *
     * @return void
     */
    public function testBareFormKeepsA404ThatStillApplies(): void
    {
        $this->router()->get('users/{user?}', [PathPlainController::class, 'report'])->defaults('user', 1);
        $this->router()->get('members/{user?}', [PathFixtureController::class, 'show']);

        $paths = $this->build();

        self::assertSame([200, 404, 500], $this->statusesOf($paths, '/users', 'get'));
        self::assertSame([200, 403, 404, 422, 500], $this->statusesOf($paths, '/members', 'get'));
    }

    /**
     * Provide whether the explicit route is registered before the optional one.
     *
     * @return iterable<string, array{bool}>
     */
    public static function provideRegistrationOrders(): iterable
    {
        yield 'explicit route first' => [true];

        yield 'explicit route last' => [false];
    }

    /**
     * Test that an explicit route keeps the path and verb a bare form also
     * matches, while the bare form fills the verbs the explicit route leaves
     * free.
     *
     * @param  bool  $explicitFirst
     * @return void
     */
    #[DataProvider('provideRegistrationOrders')]
    public function testExplicitRouteKeepsThePathAndVerbABareFormMatches(bool $explicitFirst): void
    {
        config()->set('api-toolkit.openapi.operation_ids', true);

        $explicit = fn (): mixed => $this->router()->get('reports', [PathPlainController::class, 'index'])->name('reports.index');

        if ($explicitFirst) {
            $explicit();
        }

        $this->router()->match(['GET', 'POST'], 'reports/{period?}', [PathPlainController::class, 'report'])->name('reports.show');

        if (!$explicitFirst) {
            $explicit();
        }

        $paths = $this->build();
        $shape = array_map(array_keys(...), $paths);

        self::assertSame(['/reports' => ['get', 'post'], '/reports/{period}' => ['get', 'post']], $shape);
        self::assertSame('reports.index', $this->operationOf($paths, '/reports', 'get')['operationId']);
        self::assertSame('reports.show.post', $this->operationOf($paths, '/reports', 'post')['operationId']);
        self::assertSame('reports.show.period', $this->operationOf($paths, '/reports/{period}', 'get')['operationId']);
        self::assertSame('reports.show.period.post', $this->operationOf($paths, '/reports/{period}', 'post')['operationId']);
        self::assertSame($shape, array_map(array_keys(...), $this->collector()->collect(['public'])->operations('public')));
    }

    /**
     * Test that a route's description describes every form, unless a form has
     * its own.
     *
     * @return void
     */
    public function testRouteDescriptionDescribesEveryFormUnlessAFormHasItsOwn(): void
    {
        config()->set('api-toolkit.openapi.operation_ids', true);

        $this->router()->get('reports/{a?}/{b?}', [PathPlainController::class, 'report'])->name('reports.show');

        $paths = $this->builder()->build($this->collector()->collect(['public'])->in('public'), [
            'reports.show'   => 'Shows a report.',
            'reports.show.a' => 'Shows a report for one period.',
        ], 'public', []);

        self::assertSame('Shows a report.', $this->operationOf($paths, '/reports', 'get')['description']);
        self::assertSame('Shows a report for one period.', $this->operationOf($paths, '/reports/{a}', 'get')['description']);
        self::assertSame('Shows a report.', $this->operationOf($paths, '/reports/{a}/{b}', 'get')['description']);
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
            (new EnvelopeBuilder)->anyCollectionEnvelopeFor(['$ref' => '#/components/schemas/User']),
            $schema,
        );
    }

    /**
     * Test that a declared DTO references its component under the data
     * property.
     *
     * @return void
     */
    public function testResponseSchemaDtoActionReferencesComponent(): void
    {
        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);

        $schema = $this->build()['/widget']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(['$ref' => self::WIDGET_REF], $schema['properties']['data']);
        self::assertSame(['data'], $schema['required']);
    }

    /**
     * Test that a declared DTO collection references the DTO's component as the
     * item schema.
     *
     * @return void
     */
    public function testResponseSchemaDtoCollectionActionReferencesComponent(): void
    {
        $this->router()->get('widgets', [PathResponseSchemaController::class, 'widgets']);

        $schema = $this->build()['/widgets']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            (new EnvelopeBuilder)->anyCollectionEnvelopeFor(['$ref' => self::WIDGET_REF]),
            $schema,
        );
    }

    /**
     * Test that several declared shapes embed a discriminated oneOf of their
     * references under data.
     *
     * @return void
     */
    public function testResponseSchemaUnionActionEmbedsOneOf(): void
    {
        $this->router()->get('session', [PathResponseSchemaController::class, 'union']);

        $schema = $this->build()['/session']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(self::SIGN_IN_UNION),
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
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(self::SIGN_IN_UNION)]],
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
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelope(self::SIGNED_IN_REF)]],
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
            (new EnvelopeBuilder)->singleEnvelope(self::WIDGET_REF),
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
            'headers'     => $envelope->collectionHeaders(),
            'content'     => ['application/json' => ['schema' => $envelope->anyCollectionEnvelopeFor(['$ref' => self::WIDGET_REF])]],
        ], $paths['/users']['get']['responses'][206]);
        self::assertIsArray($paths['/users/{user}']['delete']['responses']);
        self::assertSame([200, 403, 404, 500], array_keys($paths['/users/{user}']['delete']['responses']));
        self::assertSame([
            'description' => 'OK.',
            'content'     => ['application/json' => ['schema' => $envelope->singleEnvelope(self::WIDGET_REF)]],
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
        self::assertSame(['Fields', 'Aggregates'], $this->referencedParameterNames($operation));
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
            ['Fields', 'Aggregates', 'filters', 'order', 'Limit', 'Page', 'Cursor', 'Pagination'],
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
        self::assertSame(['Fields', 'Aggregates'], $this->referencedParameterNames($operation));
        self::assertSame([200, 403, 404, 422, 500], array_keys($responses));
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
            'headers'     => $envelope->collectionHeaders(),
            'content'     => ['application/json' => ['schema' => $envelope->anyCollectionEnvelopeFor(['$ref' => '#/components/schemas/User'])]],
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
        self::assertSame([], $this->builder()->build([], ['path-fixture.index' => 'Lists the users.'], 'public', []));
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
     * Test that a domain's parameters are not path parameters, and a name in
     * both the domain and the URI is one path parameter.
     *
     * @return void
     */
    public function testDomainParametersAreNotPathParameters(): void
    {
        RouteFacade::domain('{account}.example.com')->get('projects', [PathPlainController::class, 'index']);
        RouteFacade::domain('{team}.example.com')->get('teams/{team}', [PathPlainController::class, 'report']);

        $paths = $this->build();

        self::assertArrayNotHasKey('parameters', $this->operationOf($paths, '/projects', 'get'));
        self::assertContains(404, $this->statusesOf($paths, '/projects', 'get'));
        self::assertSame(['team'], array_column($this->pathParametersIn($paths['/teams/{team}']['get']['parameters']), 'name'));
        self::assertSame([['url' => 'https://{team}.example.com', 'variables' => ['team' => ['default' => 'team']]]], $paths['/teams/{team}']['get']['servers']);
    }

    /**
     * Test that a route on a domain lists its servers after its security, and a
     * route without one lists none.
     *
     * @return void
     */
    public function testDomainRouteListsItsOwnServers(): void
    {
        RouteFacade::domain('{account}.example.com')->get('projects', [PathPlainController::class, 'index']);
        $this->router()->get('reports', [PathPlainController::class, 'report']);

        $paths = $this->build(servers: [['url' => 'https://api.example.com/v1', 'description' => 'Production']]);

        self::assertSame(['tags', 'responses', 'security', 'servers'], array_keys($this->operationOf($paths, '/projects', 'get')));
        self::assertSame([[
            'url'         => 'https://{account}.example.com/v1',
            'description' => 'Production',
            'variables'   => ['account' => ['default' => 'account']],
        ]], $paths['/projects']['get']['servers']);
        self::assertArrayNotHasKey('servers', $this->operationOf($paths, '/reports', 'get'));
    }

    /**
     * Test that a domain server variable rebinding one the server still uses
     * fails the build, naming the audience, the server and the route.
     *
     * @return void
     */
    public function testDomainParameterRebindingAServerVariableFailsTheBuild(): void
    {
        RouteFacade::domain('{account}.example.com')->get('projects', [PathPlainController::class, 'index']);

        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            'Unable to export audience [public]: server [https://api.example.com/{account}] uses the variable [account] outside its host,'
                . ' which the domain of [GET {account}.example.com/projects (%s::index)] binds.',
            PathPlainController::class,
        ));

        $this->build(servers: [['url' => 'https://api.example.com/{account}', 'variables' => ['account' => ['default' => 'acme']]]]);
    }

    /**
     * Test that one controller action on two domains is one operation listing
     * the servers of both, in route order, each kept once.
     *
     * @return void
     */
    public function testOneActionOnTwoDomainsListsBothServers(): void
    {
        RouteFacade::domain('a.example.com')->get('reports', [PathPlainController::class, 'report']);
        RouteFacade::domain('b.example.com')->get('reports', [PathPlainController::class, 'report']);
        RouteFacade::domain('{account}.example.com')->post('reports', [PathPlainController::class, 'report']);
        RouteFacade::domain('{account?}.example.com')->post('reports', ['https', 'uses' => PathPlainController::class . '@report']);

        $paths = $this->build(servers: [['url' => 'https://api.example.com'], ['url' => 'http://localhost:8000']]);

        self::assertSame(
            ['https://a.example.com', 'http://a.example.com:8000', 'https://b.example.com', 'http://b.example.com:8000'],
            $this->serverUrlsOf($paths, '/reports', 'get'),
        );
        self::assertSame(
            ['https://{account}.example.com', 'http://{account}.example.com:8000', 'https://{account}.example.com:8000'],
            $this->serverUrlsOf($paths, '/reports', 'post'),
        );
        self::assertSame(['/reports'], array_keys($paths));
        self::assertSame(['get', 'post'], array_keys($paths['/reports']));
    }

    /**
     * Test that every form of one action with an optional parameter on two
     * domains lists the servers of both.
     *
     * @return void
     */
    public function testEveryFormOfOneActionOnTwoDomainsListsBothServers(): void
    {
        RouteFacade::domain('a.example.com')->get('reports/{period?}', [PathPlainController::class, 'report']);
        RouteFacade::domain('b.example.com')->get('reports/{period?}', [PathPlainController::class, 'report']);

        $paths = $this->build();

        self::assertSame(['https://a.example.com', 'https://b.example.com'], $this->serverUrlsOf($paths, '/reports', 'get'));
        self::assertSame(['https://a.example.com', 'https://b.example.com'], $this->serverUrlsOf($paths, '/reports/{period}', 'get'));
    }

    /**
     * Test that joining two domain routes keeps servers differing only in
     * values that compare loosely equal.
     *
     * @return void
     */
    public function testJoinedDomainRoutesKeepLooselyEqualServers(): void
    {
        RouteFacade::domain('{account}.example.com')->get('reports', [PathPlainController::class, 'report']);
        RouteFacade::domain('{account?}.example.com')->get('reports', [PathPlainController::class, 'report']);

        $paths = $this->build(servers: [
            ['url' => 'http://localhost:{port}', 'variables' => ['port' => ['default' => '8080']]],
            ['url' => 'http://127.0.0.1:{port}', 'variables' => ['port' => ['default' => '08080']]],
        ]);

        self::assertSame([
            ['url' => 'http://{account}.example.com:{port}', 'variables' => ['port' => ['default' => '8080'], 'account' => ['default' => 'account']]],
            ['url' => 'http://{account}.example.com:{port}', 'variables' => ['port' => ['default' => '08080'], 'account' => ['default' => 'account']]],
        ], $this->operationOf($paths, '/reports', 'get')['servers'] ?? null);
    }

    /**
     * Provide routes on several domains that claim one path and verb, the last
     * sharing no operation with the one before it.
     *
     * @return iterable<string, array{0: \Closure(): void}>
     */
    public static function distinctDomainOperationProvider(): iterable
    {
        yield 'two controller actions' => [static function (): void {
            RouteFacade::domain('a.example.com')->get('reports', [PathPlainController::class, 'index']);
            RouteFacade::domain('b.example.com')->get('reports', [PathPlainController::class, 'report']);
        }];

        yield 'two controllers' => [static function (): void {
            RouteFacade::domain('a.example.com')->get('reports', [PathAttributelessController::class, 'index']);
            RouteFacade::domain('b.example.com')->get('reports', [PathPlainController::class, 'index']);
        }];

        yield 'two closures' => [static function (): void {
            RouteFacade::domain('a.example.com')->get('reports', static fn (): string => 'a');
            RouteFacade::domain('b.example.com')->get('reports', static fn (): string => 'b');
        }];

        yield 'one action behind different middleware' => [static function (): void {
            RouteFacade::domain('a.example.com')->get('reports', [PathPlainController::class, 'report'])->middleware('auth');
            RouteFacade::domain('b.example.com')->get('reports', [PathPlainController::class, 'report']);
        }];

        yield 'one action after another operation replaced it' => [static function (): void {
            RouteFacade::domain('a.example.com')->get('reports', [PathPlainController::class, 'report']);
            RouteFacade::domain('c.example.com')->get('reports', [PathPlainController::class, 'report'])->middleware('auth');
            RouteFacade::domain('b.example.com')->get('reports', [PathPlainController::class, 'report']);
        }];
    }

    /**
     * Test that a later route on another domain replaces the earlier operation
     * unless both share one controller action and operation.
     *
     * @param  \Closure(): void  $register
     * @return void
     */
    #[DataProvider('distinctDomainOperationProvider')]
    public function testLaterDomainRouteReplacesAnotherOperation(\Closure $register): void
    {
        $register();

        self::assertSame([['url' => 'https://b.example.com']], $this->build()['/reports']['get']['servers']);
    }

    /**
     * Test that one action declaring examples on two domains is still one
     * operation, listing both servers and carrying the examples.
     *
     * @return void
     */
    public function testOneActionDeclaringExamplesOnTwoDomainsIsOneOperation(): void
    {
        $this->useDocsDirectory(['examples/auth/signed-in.json' => '{"data": {}}', 'examples/auth/remembered.json' => '{"data": {}}']);

        RouteFacade::domain('a.example.com')->post('sign-in', [PathResponseExampleController::class, 'signIn']);
        RouteFacade::domain('b.example.com')->post('sign-in', [PathResponseExampleController::class, 'signIn']);

        $operation = $this->operationOf($this->build(), '/sign-in', 'post');

        self::assertSame([['url' => 'https://a.example.com'], ['url' => 'https://b.example.com']], $operation['servers'] ?? null);
        self::assertSame(['signed-in', 'remembered'], $this->examplesOf(['/sign-in' => ['post' => $operation]], '/sign-in', 'post', 200));
    }

    /**
     * Test that only the operation a path and verb keeps reads its declared
     * examples.
     *
     * @return void
     */
    public function testOverwrittenOperationReadsNoExamples(): void
    {
        $this->useDocsDirectory(['examples/auth/signed-in.json' => '{"data": {}}', 'examples/auth/remembered.json' => '{"data": {}}']);

        RouteFacade::domain('a.example.com')->post('sign-in', [PathResponseExampleController::class, 'challenge']);
        $this->router()->post('sign-in', [PathResponseExampleController::class, 'signIn']);

        $routes = $this->collector()->collect(['public'])->in('public');

        self::assertCount(2, $routes);
        self::assertSame(['signed-in', 'remembered'], $this->examplesOf($this->builder()->build($routes, [], 'public', []), '/sign-in', 'post', 200));
    }

    /**
     * Test that a route without a domain replaces a domain route on its path
     * and verb, and a domain route replaces an earlier one without a domain.
     *
     * @return void
     */
    public function testRouteWithAndWithoutADomainReplaceEachOther(): void
    {
        RouteFacade::domain('a.example.com')->get('reports', [PathPlainController::class, 'report']);
        $this->router()->get('reports', [PathPlainController::class, 'report']);

        $routes = $this->collector()->collect(['public'])->in('public');

        self::assertArrayNotHasKey('servers', $this->operationOf($this->builder()->build($routes, [], 'public', []), '/reports', 'get'));
        self::assertSame([['url' => 'https://a.example.com']], $this->builder()->build(array_reverse($routes), [], 'public', [])['/reports']['get']['servers']);
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
     * Test that an action absent from its controller still documents its
     * success beside its error statuses.
     *
     * @return void
     */
    public function testRoutedActionAbsentFromControllerDocumentsItsSuccess(): void
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
        self::assertSame([200, 401, 403, 422, 500], array_keys($responses));
        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $responses[200]['content']['application/json']['schema']['anyOf'][0],
        );
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
            'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelope(self::WIDGET_REF)]],
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
            (new EnvelopeBuilder)->singleEnvelope(self::WIDGET_REF),
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
        self::assertSame([206, 403, 422, 500], array_keys($responses));
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
     * Test that a route requiring If-Match documents a required field and a 428
     * on the verbs that replace, modify or delete, and on no other verb.
     *
     * @return void
     */
    public function testRequiredIfMatchIsDocumentedOnConditionalVerbsOnly(): void
    {
        $this->registerDocumentRoutes(RequireIfMatch::class);
        $this->router()->match(['GET', 'DELETE'], 'documents/{document}/archive', [PathDocumentController::class, 'destroy'])->middleware(RequireIfMatch::class);

        $paths = $this->build();

        $conditional = [['/documents/{document}', 'put'], ['/documents/{document}', 'patch'], ['/documents/{document}', 'delete'], ['/documents/{document}/archive', 'delete']];
        $other       = [['/documents', 'get'], ['/documents', 'post'], ['/documents/{document}', 'get'], ['/documents/{document}/archive', 'get']];

        foreach ($conditional as [$path, $verb]) {
            self::assertSame([$this->ifMatchParameter(true)], $this->headerParametersOf($paths, $path, $verb));
            self::assertContains(428, $this->statusesOf($paths, $path, $verb));
        }

        foreach ($other as [$path, $verb]) {
            self::assertSame([], $this->headerParametersOf($paths, $path, $verb));
            self::assertNotContains(428, $this->statusesOf($paths, $path, $verb));
        }
    }

    /**
     * Test that a route requiring a JSON body documents a 400 and a 415 on the
     * verbs that send a body, and on no other verb of the same route.
     *
     * @return void
     */
    public function testRequiredJsonBodyIsDocumentedOnBodiedVerbsOnly(): void
    {
        $this->registerDocumentRoutes(RequireJsonBody::class);
        $this->router()->match(['GET', 'POST'], 'documents/{document}/copy', [PathDocumentController::class, 'show'])->middleware(RequireJsonBody::class);
        $this->router()->match(['PATCH', 'DELETE'], 'documents/{document}/archive', [PathDocumentController::class, 'destroy'])->middleware(RequireJsonBody::class);

        $paths = $this->build();

        $bodied = [
            ['/documents', 'post'],
            ['/documents/{document}', 'put'],
            ['/documents/{document}', 'patch'],
            ['/documents/{document}/copy', 'post'],
            ['/documents/{document}/archive', 'patch'],
        ];
        $other = [
            ['/documents', 'get'],
            ['/documents/{document}', 'get'],
            ['/documents/{document}', 'delete'],
            ['/documents/{document}/copy', 'get'],
            ['/documents/{document}/archive', 'delete'],
        ];

        foreach ($bodied as [$path, $verb]) {
            self::assertContains(400, $this->statusesOf($paths, $path, $verb));
            self::assertContains(415, $this->statusesOf($paths, $path, $verb));
        }

        foreach ($other as [$path, $verb]) {
            self::assertNotContains(400, $this->statusesOf($paths, $path, $verb));
            self::assertNotContains(415, $this->statusesOf($paths, $path, $verb));
        }
    }

    /**
     * Test that a route requiring both If-Match and a JSON body documents each
     * refusal only on the verbs it guards.
     *
     * @return void
     */
    public function testIfMatchAndJsonBodyAreDocumentedOnTheirOwnVerbs(): void
    {
        $this->router()->match(['POST', 'PATCH', 'DELETE'], 'documents/{document}', [PathDocumentController::class, 'update'])
            ->middleware([RequireIfMatch::class, RequireJsonBody::class]);

        $paths    = $this->build();
        $refusals = [];

        foreach (['post', 'patch', 'delete'] as $verb) {
            $refusals[$verb] = array_values(array_intersect($this->statusesOf($paths, '/documents/{document}', $verb), [415, 428]));
        }

        self::assertSame(['post' => [415], 'patch' => [415, 428], 'delete' => [428]], $refusals);
    }

    /**
     * Test that an action declaring a precondition failure documents an
     * optional If-Match field beside its 412, and no other operation does.
     *
     * @return void
     */
    public function testDeclaredPreconditionFailureDocumentsAnOptionalIfMatch(): void
    {
        $this->registerDocumentRoutes();

        $paths = $this->build();

        foreach (['put', 'patch'] as $verb) {
            self::assertSame([$this->ifMatchParameter(false)], $this->headerParametersOf($paths, '/documents/{document}', $verb));
            self::assertContains(412, $this->statusesOf($paths, '/documents/{document}', $verb));
            self::assertNotContains(428, $this->statusesOf($paths, '/documents/{document}', $verb));
        }

        foreach ([['/documents/{document}', 'delete'], ['/documents/{document}', 'get'], ['/documents', 'post']] as [$path, $verb]) {
            self::assertSame([], $this->headerParametersOf($paths, $path, $verb));
            self::assertNotContains(412, $this->statusesOf($paths, $path, $verb));
        }
    }

    /**
     * Test that the single-resource responses of a versioned model reference
     * the entity-tag header, and those of another model do not.
     *
     * @return void
     */
    public function testVersionedResourceResponsesReferenceTheEntityTag(): void
    {
        $this->registerDocumentRoutes();
        $this->registerRestRoutes();

        $paths = $this->build();
        $etag  = ['ETag' => ['$ref' => '#/components/headers/ETag']];

        $collection = $this->responseOf($paths, '/documents', 'get', 200)['headers'] ?? null;

        self::assertSame($etag, $this->responseOf($paths, '/documents/{document}', 'get', 200)['headers'] ?? null);
        self::assertSame($etag, $this->responseOf($paths, '/documents', 'post', 201)['headers'] ?? null);
        self::assertSame($etag, $this->responseOf($paths, '/documents/{document}', 'patch', 200)['headers'] ?? null);
        self::assertIsArray($collection);
        self::assertSame(['Total-Count'], array_keys($collection));
        self::assertArrayNotHasKey('headers', $this->responseOf($paths, '/users/{user}', 'get', 200));
    }

    /**
     * Test that a replaced or emptied contract response carries no entity tag.
     *
     * @return void
     */
    public function testReplacedOrEmptiedContractCarriesNoEntityTag(): void
    {
        $this->router()->post('documents', [PathDocumentDeclarationsController::class, 'store']);
        $this->router()->patch('documents/{document}', [PathDocumentDeclarationsController::class, 'update']);

        $paths = $this->build();

        self::assertArrayNotHasKey('headers', $this->responseOf($paths, '/documents', 'post', 201));
        self::assertSame(['description' => 'No Content.'], $this->responseOf($paths, '/documents/{document}', 'patch', 204));
    }

    /**
     * Test that redeclaring the entity tag on a versioned contract response
     * fails the build.
     *
     * @return void
     */
    public function testRedeclaringTheEntityTagFailsTheBuild(): void
    {
        $this->router()->get('documents/{document}', [PathDocumentDeclarationsController::class, 'show']);

        $this->expectExceptionObject(new ResponseHeaderCollisionException(sprintf(
            'The etag header declared for %s::show is emitted by the exporter and cannot be redeclared',
            PathDocumentDeclarationsController::class,
        )));

        $this->build();
    }

    /**
     * Test that a route answering only HEAD or OPTIONS is not built, so its
     * action's status conflict cannot fail the build.
     *
     * @return void
     */
    public function testRouteWithoutADocumentableVerbIsNotBuilt(): void
    {
        $this->router()->match(['HEAD'], 'users/{user}', [PathStatusConflictController::class, 'update']);
        $this->router()->options('users/{user}/reset', [PathStatusConflictController::class, 'reset']);

        self::assertSame([], $this->build());
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
     * Register the REST routes of the versioned document controller with the
     * given middleware.
     *
     * @param  string  ...$middleware
     * @return void
     */
    private function registerDocumentRoutes(string ...$middleware): void
    {
        $router = $this->router();

        $router->get('documents', [PathDocumentController::class, 'index'])->middleware($middleware);
        $router->post('documents', [PathDocumentController::class, 'store'])->middleware($middleware);
        $router->get('documents/{document}', [PathDocumentController::class, 'show'])->middleware($middleware);
        $router->match(['PUT', 'PATCH'], 'documents/{document}', [PathDocumentController::class, 'update'])->middleware($middleware);
        $router->delete('documents/{document}', [PathDocumentController::class, 'destroy'])->middleware($middleware);
    }

    /**
     * Read one response of a built operation.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @param  string  $path
     * @param  string  $method
     * @param  int  $status
     * @return array<array-key, mixed>
     */
    private function responseOf(array $paths, string $path, string $method, int $status): array
    {
        $response = $this->operationOf($paths, $path, $method)['responses'][$status] ?? null;

        self::assertIsArray($response);

        return $response;
    }

    /**
     * List the header parameters a built operation documents.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @param  string  $path
     * @param  string  $method
     * @return list<mixed>
     */
    private function headerParametersOf(array $paths, string $path, string $method): array
    {
        $parameters = $this->operationOf($paths, $path, $method)['parameters'] ?? [];

        self::assertIsArray($parameters);

        return array_values(array_filter($parameters, static fn (mixed $parameter): bool => is_array($parameter) && ($parameter['in'] ?? null) === 'header'));
    }

    /**
     * Build the If-Match header parameter.
     *
     * @param  bool  $required
     * @return array<string, mixed>
     */
    private function ifMatchParameter(bool $required): array
    {
        return [
            'name'        => 'If-Match',
            'in'          => 'header',
            'description' => 'The entity tag of the resource as last read. The write is refused with a 412 when the resource has changed since.',
            'required'    => $required,
            'schema'      => ['type' => 'string'],
        ];
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
     * Build the paths object from the public audience's collected routes.
     *
     * @param  array<string, string>  $descriptions
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, array<string, mixed>>
     */
    private function build(array $descriptions = [], array $servers = []): array
    {
        return $this->builder()->build($this->collector()->collect(['public'])->in('public'), $descriptions, 'public', $servers);
    }

    /**
     * List the query parameters the operation takes, in order: a shared one by
     * its component name, an inline one by the query parameter it carries.
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

            if (!is_array($parameter) || ($parameter['in'] ?? 'query') !== 'query') {
                continue;
            }

            $name = $parameter['$ref'] ?? $parameter['name'] ?? null;

            self::assertIsString($name);

            $names[] = str_replace('#/components/parameters/', '', $name);
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
        return $this->operationOf($this->build(), $path, $method);
    }

    /**
     * List the server URLs of one operation of a built paths object, keeping
     * their keys.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @param  string  $path
     * @param  string  $method
     * @return array<array-key, mixed>
     */
    private function serverUrlsOf(array $paths, string $path, string $method): array
    {
        $servers = $this->operationOf($paths, $path, $method)['servers'] ?? null;

        self::assertIsArray($servers);

        return array_map(static fn (mixed $server): mixed => is_array($server) ? $server['url'] ?? null : null, $servers);
    }

    /**
     * Read one operation from a built paths object.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @param  string  $path
     * @param  string  $method
     * @return array<array-key, mixed>
     */
    private function operationOf(array $paths, string $path, string $method): array
    {
        $operation = $paths[$path][$method] ?? null;

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
     * List the statuses a built operation documents.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @param  string  $path
     * @param  string  $method
     * @return list<array-key>
     */
    private function statusesOf(array $paths, string $path, string $method): array
    {
        $responses = $this->operationOf($paths, $path, $method)['responses'] ?? null;

        self::assertIsArray($responses);

        return array_keys($responses);
    }

    /**
     * List the example names a built operation's error response references.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @param  string  $path
     * @param  string  $method
     * @param  int  $status
     * @return list<array-key>
     */
    private function examplesOf(array $paths, string $path, string $method, int $status): array
    {
        $examples = $this->operationOf($paths, $path, $method)['responses'][$status]['content']['application/json']['examples'] ?? null;

        self::assertIsArray($examples);

        return array_keys($examples);
    }

    /**
     * Build a path builder over the catalogue.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder
     */
    private function builder(): PathBuilder
    {
        assert($this->app !== null);

        $catalogue = $this->catalogue();
        $enums     = new EnumSchemaRegistry;

        return new PathBuilder(
            new EnvelopeBuilder,
            new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder)),
            new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($this->router())),
            new QueryParameterBuilder($catalogue),
            new PathParameterBuilder(
                $this->router(),
                new RoutePatternTranslator,
                new RouteKeySchemaResolver(
                    $catalogue,
                    new FieldTypeResolver($this->app->make(SchemaIntrospectionProvider::class), new ColumnTypeMapper, $enums),
                    new RoutePatternTranslator,
                    $enums,
                    $this->app,
                ),
                $enums,
            ),
            new ErrorResponseBuilder(
                new RouteMiddlewareResolver($this->router()),
                new ThrowsErrorResolver(new ClassNameResolver),
                new ResponseHeaderResolver,
                new ErrorExampleBuilder($catalogue),
                ExceptionMap::fromConfig(),
            ),
            new SuccessResponseBuilder(
                new EnvelopeBuilder,
                new ResponseSchemaResolver($catalogue, new EnvelopeBuilder, new DefinedSchemaRegistry),
                new ResponseHeaderResolver,
                new ResponseStatusResolver,
            ),
            new RouteMiddlewareResolver($this->router()),
        );
    }

    /**
     * Build a route collector over the catalogue.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector
     */
    private function collector(): DocumentedRouteCollector
    {
        return new DocumentedRouteCollector(
            $this->router(),
            $this->catalogue(),
            new AudienceResolver,
            new AudienceConfiguration,
            new DocumentableRouteFilter,
            new TagResolver,
        );
    }

    /**
     * Build a catalogue over the resource map, with the package's error
     * catalogue, query surfaces and the configured query limits and page sizes.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue
     */
    private function catalogue(): MetadataCatalogue
    {
        $app = $this->app;

        assert($app !== null);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($this->resourceMap);
        $catalogue->method('getErrorCatalogue')->willReturn($app->make(ErrorCatalogueReader::class)->read());
        $catalogue->method('getQueryLimits')->willReturnCallback(static fn (): array => $app->make(MetadataCatalogue::class)->getQueryLimits());
        $catalogue->method('getPageSize')->willReturnCallback(PageSize::for(...));
        $catalogue->method('getQuerySurface')->willReturnCallback(
            static fn (?string $resource, ?string $model): ?QuerySurfaceDescriptor => $app->make(MetadataCatalogue::class)->getQuerySurface($resource, $model),
        );

        return $catalogue;
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
