<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Google OAuth2 인증
$routes->get('auth/google', 'AuthController::redirect');
$routes->get('auth/google/callback', 'AuthController::callback');

// Google Photos Picker 세션
$routes->post('picker/sessions', 'PickerController::create');
$routes->get('picker/sessions/status', 'PickerController::status');
$routes->get('picker/media-items', 'PickerController::items');
$routes->post('picker/ingest', 'PickerController::ingest');
