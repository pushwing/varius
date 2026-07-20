<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->match(['get', 'head'], '/', 'Home::index');

// Google OAuth2 인증
$routes->get('auth/google', 'AuthController::redirect');
$routes->get('auth/google/callback', 'AuthController::callback');
$routes->get('auth/logout', 'AuthController::logout');

// 동선 시각화
$routes->get('routes', 'RouteController::data');
$routes->get('map', 'RouteController::map');
