<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function (RouteCollection $routes): void {
    $routes->post('tokens', 'AuthController::create');

    // CORS 프리플라이트(OPTIONS)를 라우팅 단계에서 매칭시켜 'cors' 필터가
    // 실행되게 한다 — 매칭되는 라우트가 없으면 필터보다 먼저 404가 난다.
    $routes->options('(:any)', static fn () => service('response')->setStatusCode(204));
});
