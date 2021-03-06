<?php

namespace Yiisoft\Router\Tests;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\FastRoute\FastRouteFactory;
use Yiisoft\Router\Middleware\Callback;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollectorInterface;

class GroupTest extends TestCase
{
    public function testAddRoute(): void
    {
        $listRoute = Route::get('/');
        $viewRoute = Route::get('/{id}');

        $group = new Group();
        $group->addRoute($listRoute);
        $group->addRoute($viewRoute);

        $this->assertCount(2, $group->getItems());
        $this->assertSame($listRoute, $group->getItems()[0]);
        $this->assertSame($viewRoute, $group->getItems()[1]);
    }

    public function testAddMiddleware(): void
    {
        $group = new Group();

        $middleware1 = $this->getMockBuilder(MiddlewareInterface::class)->getMock();
        $middleware2 = $this->getMockBuilder(MiddlewareInterface::class)->getMock();

        $group
            ->addMiddleware($middleware1)
            ->addMiddleware($middleware2);

        $this->assertCount(2, $group->getMiddlewares());
        $this->assertSame($middleware1, $group->getMiddlewares()[1]);
        $this->assertSame($middleware2, $group->getMiddlewares()[0]);
    }

    public function testGroupMiddlewareFullStackCalled(): void
    {
        $factory = new FastRouteFactory();
        $router = $factory();
        $group = new Group('/group', function (RouteCollectorInterface $r) {
            $r->addRoute(Route::get('/test1')->name('request1'));
        });

        $request = new ServerRequest('GET', '/group/test1');
        $middleware1 = new Callback(function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            $request = $request->withAttribute('middleware', 'middleware1');
            return $handler->handle($request);
        });
        $middleware2 = new Callback(function (ServerRequestInterface $request) {
            return new Response(200, [], null, '1.1', implode($request->getAttributes()));
        });

        $group->addMiddleware($middleware2)->addMiddleware($middleware1);

        $router->addGroupInstance($group);
        $result = $router->match($request);
        $response = $result->process($request, $this->getRequestHandler());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('middleware1', $response->getReasonPhrase());
    }

    public function testGroupMiddlewareStackInterrupted(): void
    {
        $factory = new FastRouteFactory();
        $router = $factory();
        $group = new Group('/group', function (RouteCollectorInterface $r) {
            $r->addRoute(Route::get('/test1')->name('request1'));
        });

        $request = new ServerRequest('GET', '/group/test1');
        $middleware1 = new Callback(function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            return new Response(403);
        });
        $middleware2 = new Callback(function (ServerRequestInterface $request) {
            return new Response(200);
        });

        $group->addMiddleware($middleware2)->addMiddleware($middleware1);

        $router->addGroupInstance($group);
        $result = $router->match($request);
        $response = $result->process($request, $this->getRequestHandler());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAddGroup(): void
    {
        $logoutRoute = Route::post('/logout');
        $listRoute = Route::get('/');
        $viewRoute = Route::get('/{id}');

        $middleware1 = $this->getMockBuilder(MiddlewareInterface::class)->getMock();
        $middleware2 = $this->getMockBuilder(MiddlewareInterface::class)->getMock();

        $root = new Group();
        $root->addGroup('/api', static function (Group $group) use ($logoutRoute, $listRoute, $viewRoute, $middleware1, $middleware2) {
            $group->addRoute($logoutRoute);
            $group->addGroup('/post', static function (Group $group) use ($listRoute, $viewRoute) {
                $group->addRoute($listRoute);
                $group->addRoute($viewRoute);
            });

            $group->addMiddleware($middleware1);
            $group->addMiddleware($middleware2);
        });

        $this->assertCount(1, $root->getItems());
        $api = $root->getItems()[0];

        $this->assertSame('/api', $api->getPrefix());
        $this->assertCount(2, $api->getItems());
        $this->assertSame($logoutRoute, $api->getItems()[0]);

        /** @var Group $postGroup */
        $postGroup = $api->getItems()[1];
        $this->assertInstanceOf(Group::class, $postGroup);
        $this->assertCount(2, $api->getMiddlewares());
        $this->assertSame($middleware1, $api->getMiddlewares()[1]);
        $this->assertSame($middleware2, $api->getMiddlewares()[0]);

        $this->assertSame('/post', $postGroup->getPrefix());
        $this->assertCount(2, $postGroup->getItems());
        $this->assertSame($listRoute, $postGroup->getItems()[0]);
        $this->assertSame($viewRoute, $postGroup->getItems()[1]);
        $this->assertEmpty($postGroup->getMiddlewares());
    }

    public function testAddGroupSecondWay(): void
    {
        $logoutRoute = Route::post('/logout');
        $listRoute = Route::get('/');
        $viewRoute = Route::get('/{id}');

        $middleware1 = $this->getMockBuilder(MiddlewareInterface::class)->getMock();
        $middleware2 = $this->getMockBuilder(MiddlewareInterface::class)->getMock();

        $root = Group::create(null, [
            Group::create('/api', [
                $logoutRoute,
                Group::create('/post', [
                    $listRoute,
                    $viewRoute
                ])
            ])->addMiddleware($middleware1)->addMiddleware($middleware2)
        ]);

        $this->assertCount(1, $root->getItems());
        $api = $root->getItems()[0];

        $this->assertSame('/api', $api->getPrefix());
        $this->assertCount(2, $api->getItems());
        $this->assertSame($logoutRoute, $api->getItems()[0]);

        /** @var Group $postGroup */
        $postGroup = $api->getItems()[1];
        $this->assertInstanceOf(Group::class, $postGroup);
        $this->assertCount(2, $api->getMiddlewares());
        $this->assertSame($middleware1, $api->getMiddlewares()[1]);
        $this->assertSame($middleware2, $api->getMiddlewares()[0]);

        $this->assertSame('/post', $postGroup->getPrefix());
        $this->assertCount(2, $postGroup->getItems());
        $this->assertSame($listRoute, $postGroup->getItems()[0]);
        $this->assertSame($viewRoute, $postGroup->getItems()[1]);
        $this->assertEmpty($postGroup->getMiddlewares());
    }

    private function getRequestHandler(): RequestHandlerInterface
    {
        return new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };
    }
}
