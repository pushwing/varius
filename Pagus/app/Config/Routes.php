<?php

namespace Config;

$routes = Services::routes();

$routes->get('/', 'AdminAuthController::login');
$routes->get('login', 'AdminAuthController::login');
$routes->post('login', 'AdminAuthController::authenticate');
$routes->post('logout', 'AdminAuthController::logout', ['filter' => 'admin']);
$routes->get('admin', 'AdminController::index', ['filter' => 'admin']);
