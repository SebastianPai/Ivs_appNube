<?php

/*
 * Punto de entrada para cPanel.
 * La aplicación vive FUERA de public_html (en ~/ivs_administracion) para que .env, el código y las
 * credenciales de Firebase no se puedan descargar desde la web. Aquí solo están los archivos públicos.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$aplicacion = dirname(__DIR__).'/ivs_administracion';

if (file_exists($maintenance = $aplicacion.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $aplicacion.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $aplicacion.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
