<?php

namespace Config;

$routes = Services::routes();

$routes->get('/', 'AdminAuthController::login');
$routes->get('login', 'AdminAuthController::login');
$routes->post('login', 'AdminAuthController::authenticate');
$routes->post('logout', 'AdminAuthController::logout', ['filter' => 'admin']);
$routes->get('admin', 'AdminController::index', ['filter' => 'admin']);
$routes->get('admin/restaurants/(:num)/edit', 'AdminController::editRestaurant/$1', ['filter' => 'admin']);
$routes->get('admin/categories/(:num)/edit', 'AdminController::editCategory/$1', ['filter' => 'admin']);
$routes->post('admin/categories/save', 'AdminController::saveCategory', ['filter' => 'admin']);
$routes->post('admin/categories/(:num)/toggle', 'AdminController::toggleCategory/$1', ['filter' => 'admin']);
$routes->post('admin/restaurants/save', 'AdminController::saveRestaurant', ['filter' => 'admin']);
$routes->post('admin/restaurants/(:num)/toggle', 'AdminController::toggleRestaurant/$1', ['filter' => 'admin']);
