<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->match(['get', 'head'], '/', 'Home::index');

// Google OAuth2 인증
$routes->get('auth/google', 'AuthController::redirect');
$routes->get('auth/google/callback', 'AuthController::callback');
$routes->get('auth/logout', 'AuthController::logout');

// Google Photos Picker 세션
$routes->post('picker/sessions', 'PickerController::create', ['filter' => 'sessionRateLimit']);
$routes->get('picker/sessions/status', 'PickerController::status');
$routes->get('picker/media-items', 'PickerController::items');
$routes->post('picker/ingest', 'PickerController::ingest');

// 동선 시각화
$routes->get('routes', 'RouteController::data');
$routes->get('map', 'RouteController::map');
