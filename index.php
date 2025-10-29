<?php
/**
 * Lumen API - Entry Point
 * API REST para el sitio web de Liceo Lumen
 */

// Configuración de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar zona horaria
require_once 'env.php';
date_default_timezone_set(TIMEZONE);

// Cargar FlightPHP
require 'flight/Flight.php';

// Aplicar middleware de CORS ANTES de cualquier otra cosa
require_once 'middleware/CorsMiddleware.php';
CorsMiddleware::handle();

// Cargar otros middlewares
require_once 'middleware/RateLimitMiddleware.php';

// Función helper para responder JSON
function responderJSON($data, $code = 200) {
    Flight::response()->status($code);
    Flight::response()->header('Content-Type', 'application/json; charset=utf-8');
    Flight::response()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    Flight::response()->send();
    exit;
}

// Cargar servicios
foreach (glob(__DIR__ . '/services/*.php') as $serviceFile) {
    require_once $serviceFile;
}

// Cargar rutas
foreach (glob(__DIR__ . '/routes/*.php') as $routeFile) {
    require_once $routeFile;
}

// Ruta raíz
Flight::route('/', function () {
    Flight::json([
        'service' => 'Lumen API',
        'version' => '1.0.0',
        'status' => 'active',
        'message' => 'Bienvenido a la API de Liceo Lumen',
        'endpoints' => [
            'GET /' => 'Información de la API',
            'GET /api/test' => 'Test básico',
            'GET /api/test/db' => 'Test de base de datos',
            'GET /api/health' => 'Health check'
        ]
    ]);
});

// Configurar PDO con opciones correctas
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '-05:00';",
    PDO::ATTR_STRINGIFY_FETCHES => false,
    PDO::ATTR_EMULATE_PREPARES => false
];

// Registrar conexión a base de datos
Flight::register('db', 'PDO', array(
    DB_DSN,
    DB_USERNAME,
    DB_PASSWORD,
    $options
));

// Configurar zona horaria de MySQL después de conectar
Flight::after('db', function($db) {
    try {
        $db->exec("SET time_zone = '-05:00'");
    } catch (Exception $e) {
        error_log("Error configurando zona horaria MySQL: " . $e->getMessage());
    }
});

// Manejo de errores 404
Flight::map('notFound', function() {
    Flight::json([
        'error' => 'Endpoint no encontrado',
        'message' => 'La ruta solicitada no existe',
        'status' => 404
    ], 404);
});

// Manejo de errores generales
Flight::map('error', function($error) {
    error_log("Error en API: " . $error->getMessage());
    
    Flight::json([
        'error' => 'Error interno del servidor',
        'message' => 'Ha ocurrido un error inesperado',
        'status' => 500
    ], 500);
});

// Iniciar Flight
Flight::start();
