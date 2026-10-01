<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('health', 'Health::index');
$routes->get('api/v1/auth/csrf', '\App\Modules\Identity\Http\AuthController::csrf');
$routes->post('api/v1/auth/login', '\App\Modules\Identity\Http\AuthController::login', ['filter' => 'auth-rates']);
$routes->post('api/v1/auth/logout', '\App\Modules\Identity\Http\AuthController::logout');
$routes->get('api/v1/auth/me', '\App\Modules\Identity\Http\AuthController::me');
$routes->get('api/v1/profiles', 'ProfileController::index');
$routes->get('api/v1/summary', 'JobsController::summary');
$routes->post('api/v1/vms', 'JobsController::create');
$routes->get('api/v1/vms', 'JobsController::vms');
$routes->get('api/v1/vms/(:segment)', 'JobsController::vm/$1');
$routes->get('api/v1/jobs', 'JobsController::jobs');
$routes->get('api/v1/jobs/(:segment)', 'JobsController::job/$1');
$routes->post('internal/v1/vmm-completions', 'CompletionController::receive');
