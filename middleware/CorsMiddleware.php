<?php
/**
 * Middleware de CORS
 * Controla qué dominios pueden acceder a la API
 */

class CorsMiddleware
{
    public static function handle()
    {
        // Obtener el origen de la petición
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        
        // Verificar si el origen está en la lista de permitidos
        if (in_array($origin, ALLOWED_ORIGINS)) {
            header("Access-Control-Allow-Origin: $origin");
        }
        
        // Headers permitidos
        header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Allow: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Credentials: true");
        
        // Manejar preflight requests
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method == "OPTIONS") {
            http_response_code(200);
            exit();
        }
    }
}
