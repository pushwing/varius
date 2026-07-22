<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->match(['get', 'head'], '/', 'Home::index');

// Google OAuth2 인증
$routes->get('auth/google', 'AuthController::redirect');
$routes->get('auth/google/callback', 'AuthController::callback');
$routes->get('auth/logout', 'AuthController::logout');

// 사진 zip 업로드
$routes->get('upload', 'TakeoutController::form');
$routes->post('takeout/upload', 'TakeoutController::upload', ['filter' => 'sessionRateLimit']); // Google Takeout(JSON 사이드카)
$routes->post('photos/upload', 'TakeoutController::uploadPlain', ['filter' => 'sessionRateLimit']); // 일반 압축파일(사진 EXIF)

// 동선 시각화
$routes->get('routes', 'RouteController::data');
$routes->get('map', 'RouteController::map');
$routes->get('thumbnails/(:num)', 'RouteController::thumbnail/$1');

// 계정·데이터 삭제
$routes->post('account/delete', 'AccountController::deleteData', ['filter' => 'sessionRateLimit']);
