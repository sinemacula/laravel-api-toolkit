<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use Tests\Fixtures\Controllers\TestingAuthorizedController;
use Tests\Fixtures\Controllers\TestingController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class RoutingControllerTest extends TestCase
{
    public function testControllerRespondWithDataItemAndCollectionHelpers(): void
    {
        $controller = new TestingController;

        $dataResponse = $controller->data(['id' => 1], HttpStatus::CREATED, ['X-Test' => 'yes']);

        static::assertInstanceOf(JsonResponse::class, $dataResponse);
        static::assertSame(201, $dataResponse->getStatusCode());
        static::assertSame('yes', $dataResponse->headers->get('X-Test'));
        static::assertSame(['data' => ['id' => 1]], $dataResponse->getData(true));

        $itemResponse = $controller->item(new UserResource((object) ['id' => 1, 'name' => 'Alice']), HttpStatus::ACCEPTED);

        static::assertSame(202, $itemResponse->getStatusCode());

        $collection = UserResource::collection(new Collection([
            (object) ['id' => 1, 'name' => 'Alice'],
            (object) ['id' => 2, 'name' => 'Bob'],
        ]));

        $collectionResponse = $controller->collection($collection, HttpStatus::OK, ['X-Col' => '1']);

        static::assertSame('1', $collectionResponse->headers->get('X-Col'));
    }

    public function testControllerRespondWithEventStreamSendsDataAndHeartbeats(): void
    {
        $controller = new TestingController;

        FunctionOverrides::setConnectionAbortedSequence([0, 0, 1]);

        $calls = 0;

        $response = $controller->stream(function () use (&$calls): void {
            $calls++;
            echo 'data: ping\n\n';
        }, 1, HttpStatus::OK, ['X-Stream' => 'on']);

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        static::assertSame('on', $response->headers->get('X-Stream'));
        static::assertStringContainsString('data: ping', $content);
        static::assertGreaterThanOrEqual(1, FunctionOverrides::flushCalls());
        static::assertSame(1, FunctionOverrides::sleepCalls());
        static::assertSame(1, $calls);
    }

    public function testControllerEventStreamCanExitOnSecondConnectionCheck(): void
    {
        $controller = new TestingController;

        FunctionOverrides::setConnectionAbortedSequence([0, 1]);

        $response = $controller->stream(static function (): void {
            echo "data: once\n\n";
        });

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        static::assertStringContainsString('data: once', $content);
    }

    public function testAuthorizedControllerStaticMetadataHelpers(): void
    {
        static::assertInstanceOf(TestingAuthorizedController::class, new TestingAuthorizedController);
        static::assertSame(User::class, TestingAuthorizedController::getResourceModel());
        static::assertSame('user', TestingAuthorizedController::getRouteParameter());
        static::assertNull(MissingRouteParameterAuthorizedController::getRouteParameter());
    }

    public function testAuthorizedControllerThrowsWhenResourceModelConstantMissing(): void
    {
        $this->expectException(\LogicException::class);

        MissingResourceModelAuthorizedController::getResourceModel();
    }
}

class MissingResourceModelAuthorizedController extends \SineMacula\ApiToolkit\Http\Routing\AuthorizedController {}

class MissingRouteParameterAuthorizedController extends \SineMacula\ApiToolkit\Http\Routing\AuthorizedController
{
    public const string RESOURCE_MODEL = User::class;
}
