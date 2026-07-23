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

// 위치기록(Timeline.json) — 업로드는 무거운 동기 처리라 레이트리밋 적용
$routes->post('location-history/upload', 'LocationHistoryController::upload', ['filter' => 'sessionRateLimit']);
$routes->get('location-history/track/(:segment)', 'LocationHistoryController::track/$1');

// 동선 시각화
$routes->get('routes', 'RouteController::data');
$routes->get('map', 'RouteController::map');
$routes->get('thumbnails/(:num)', 'RouteController::thumbnail/$1');

// 발자국 지도 — 방문 국가·시·도 시각화
$routes->get('footprint', 'FootprintController::page');
$routes->get('footprint/data', 'FootprintController::data');

// 날짜별 시간 동선(여행 스케줄)
$routes->get('timeline/poi', 'TimelineController::poi', ['filter' => 'sessionRateLimit:poi,300']); // (:segment) 라우트보다 먼저 선언해야 한다
$routes->get('timeline/(:segment)', 'TimelineController::data/$1');
$routes->post('timeline/day-note', 'TimelineController::saveDayNote', ['filter' => 'sessionRateLimit:notes,120']);
$routes->post('timeline/time-note', 'TimelineController::saveTimeNote', ['filter' => 'sessionRateLimit:notes,120']);
$routes->post('timeline/share', 'TimelineController::share', ['filter' => 'sessionRateLimit:share,60']);

// SNS 공유 링크(비로그인 공개 열람)
$routes->get('s/(:segment)', 'ShareController::show/$1');
$routes->get('s/(:segment)/thumbnails/(:num)', 'ShareController::thumbnail/$1/$2');

// 사진 개별 관리(썸네일 회전·삭제)
$routes->post('photos/(:num)/rotate', 'PhotoController::rotate/$1', ['filter' => 'sessionRateLimit:photos,300']);
$routes->post('photos/(:num)/delete', 'PhotoController::delete/$1', ['filter' => 'sessionRateLimit:photos,300']);

// 여행 그룹핑
$routes->get('trips', 'TripController::index');
$routes->get('trips/data', 'TripController::data'); // (:num) 라우트보다 먼저 선언
$routes->get('trips/stats', 'TripController::stats'); // (:num) 라우트보다 먼저 선언
$routes->post('trips', 'TripController::create', ['filter' => 'sessionRateLimit:trips,120']);
$routes->get('trips/(:num)', 'TripController::show/$1');
$routes->get('trips/(:num)/data', 'TripController::showData/$1');
$routes->post('trips/(:num)/update', 'TripController::update/$1', ['filter' => 'sessionRateLimit:trips,120']);
$routes->post('trips/(:num)/delete', 'TripController::delete/$1', ['filter' => 'sessionRateLimit:trips,120']);
$routes->post('trips/(:num)/share', 'TripController::share/$1', ['filter' => 'sessionRateLimit:trips,120']);

// 여행 단위 SNS 공유 링크(비로그인 공개 열람)
$routes->get('t/(:segment)', 'TripShareController::show/$1');
$routes->get('t/(:segment)/thumbnails/(:num)', 'TripShareController::thumbnail/$1/$2');

// 계정·데이터 삭제
$routes->post('account/delete', 'AccountController::deleteData', ['filter' => 'sessionRateLimit']);
