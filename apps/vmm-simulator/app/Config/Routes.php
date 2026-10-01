<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->post('vmm/v1/jobs', 'SimulatorController::create');
$routes->get('vmm/v1/jobs/(:segment)', 'SimulatorController::show/$1');
$routes->get('health/ready', 'Health::ready');
