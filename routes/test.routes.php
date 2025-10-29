<?php
/**
 * Rutas de Test
 * Endpoints para verificar funcionamiento del API
 */

// GET /api/test - Test básico sin BD
Flight::route('GET /api/test', function() {
    TestService::getHelloWorld();
});

// GET /api/test/db - Test de conexión a base de datos
Flight::route('GET /api/test/db', function() {
    TestService::testDatabase();
});

// GET /api/health - Health check
Flight::route('GET /api/health', function() {
    Flight::json([
        'status' => 'ok',
        'service' => 'Lumen API',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
});
