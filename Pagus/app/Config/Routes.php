<?php

namespace Config;

$routes = Services::routes();

$routes->get('/', 'Home::index');
$routes->get('restaurants/(:num)', 'Home::show/$1');
$routes->get('photos/(:num)', 'PhotoController::show/$1');
$routes->post('restaurants/(:num)/reviews', 'ReviewController::create/$1');
$routes->post('reviews/(:num)/reports', 'ReviewController::report/$1');
$routes->get('login', 'AdminAuthController::login');
$routes->post('login', 'AdminAuthController::authenticate');
$routes->post('logout', 'AdminAuthController::logout', ['filter' => 'admin']);
$routes->post('inquiries', 'InquiryController::create');
$routes->get('admin/inquiries', 'AdminController::inquiries', ['filter' => 'admin']);
$routes->get('admin/reviews', 'AdminController::reviews', ['filter' => 'admin']);
$routes->post('admin/reviews/(:num)/toggle', 'AdminController::toggleReview/$1', ['filter' => 'admin']);
$routes->get('admin/inquiries/(:num)', 'AdminController::showInquiry/$1', ['filter' => 'admin']);
$routes->post('admin/inquiries/(:num)/status', 'AdminController::updateInquiryStatus/$1', ['filter' => 'admin']);
$routes->get('admin', 'AdminController::index', ['filter' => 'admin']);
$routes->get('admin/restaurants/new', 'AdminController::newRestaurant', ['filter' => 'admin']);
$routes->get('admin/restaurants/(:num)/edit', 'AdminController::editRestaurant/$1', ['filter' => 'admin']);
$routes->get('admin/restaurants/search-address', 'AdminController::searchAddress', ['filter' => 'admin']);
$routes->get('admin/restaurants/search-reference', 'AdminController::searchReference', ['filter' => 'admin']);
$routes->get('admin/restaurants/(:num)/photos', 'AdminController::managePhotos/$1', ['filter' => 'admin']);
$routes->post('admin/restaurants/(:num)/photos/upload', 'AdminController::uploadPhotos/$1', ['filter' => 'admin']);
$routes->post('admin/restaurants/(:num)/photos/(:num)/toggle', 'AdminController::togglePhoto/$1/$2', ['filter' => 'admin']);
$routes->post('admin/restaurants/(:num)/photos/(:num)/delete', 'AdminController::deletePhoto/$1/$2', ['filter' => 'admin']);
$routes->get('admin/restaurants/(:num)/photos/(:num)/file', 'PhotoController::adminShow/$1/$2', ['filter' => 'admin']);
$routes->get('admin/categories', 'AdminController::categories', ['filter' => 'admin']);
$routes->get('admin/categories/(:num)/edit', 'AdminController::editCategory/$1', ['filter' => 'admin']);
$routes->post('admin/categories/save', 'AdminController::saveCategory', ['filter' => 'admin']);
$routes->post('admin/categories/(:num)/toggle', 'AdminController::toggleCategory/$1', ['filter' => 'admin']);
$routes->post('admin/restaurants/save', 'AdminController::saveRestaurant', ['filter' => 'admin']);
$routes->post('admin/restaurants/(:num)/toggle', 'AdminController::toggleRestaurant/$1', ['filter' => 'admin']);
