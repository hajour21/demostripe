<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Check if the application is under maintenance
if (file_exists($maintenance = __DIR__ . '/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel and get the application instance
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Create the HTTP kernel
$kernel = $app->make(Kernel::class);

// Process the request and send the response
$response = $kernel->handle(
    $request = Request::capture()
)->send();

// Terminate the application
$kernel->terminate($request, $response);
