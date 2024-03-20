<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->get('resizer/read', '\Tomkirsch\Resizer\ResizerController::read'); // expects these GET params: file, size, sourceExt, destExt, (dpr)
$routes->get('resizer/cleandir', '\Tomkirsch\Resizer\ResizerController::cleandir'); // pass ?force=1 to delete all files regardless of age
$routes->get('resizer/cleanfile', '\Tomkirsch\Resizer\ResizerController::cleanfile'); // pass ?file=filename.ext to delete all versions of a single file
