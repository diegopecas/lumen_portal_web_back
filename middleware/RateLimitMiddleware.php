<?php
/**
 * Middleware de Rate Limiting
 * Limita el número de peticiones por IP
 */

class RateLimitMiddleware
{
    private static $cacheFile = __DIR__ . '/../cache/rate_limit.json';
    private static $maxRequests = 3; // Máximo de peticiones
    private static $timeWindow = 3600; // Ventana de tiempo en segundos (1 hora)
    
    public static function check($endpoint = 'default')
    {
        // Crear carpeta cache si no existe
        $cacheDir = dirname(self::$cacheFile);
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Obtener IP del cliente
        $ip = self::getClientIP();
        
        // Cargar datos de rate limiting
        $data = self::loadData();
        
        // Limpiar entradas antiguas
        $data = self::cleanOldEntries($data);
        
        // Crear clave única por IP y endpoint
        $key = md5($ip . '_' . $endpoint);
        
        // Verificar si existe el registro
        if (!isset($data[$key])) {
            $data[$key] = [
                'ip' => $ip,
                'endpoint' => $endpoint,
                'requests' => [],
                'first_request' => time()
            ];
        }
        
        // Agregar timestamp actual
        $data[$key]['requests'][] = time();
        
        // Contar peticiones en la ventana de tiempo
        $recentRequests = array_filter($data[$key]['requests'], function($timestamp) {
            return (time() - $timestamp) < self::$timeWindow;
        });
        
        $data[$key]['requests'] = array_values($recentRequests);
        
        // Guardar datos
        self::saveData($data);
        
        // Verificar si excede el límite
        if (count($recentRequests) > self::$maxRequests) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Demasiadas peticiones',
                'message' => 'Has excedido el límite de ' . self::$maxRequests . ' peticiones por hora. Intenta más tarde.',
                'retry_after' => self::$timeWindow
            ]);
            exit();
        }
        
        return true;
    }
    
    private static function getClientIP()
    {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip;
    }
    
    private static function loadData()
    {
        if (file_exists(self::$cacheFile)) {
            $content = file_get_contents(self::$cacheFile);
            return json_decode($content, true) ?: [];
        }
        return [];
    }
    
    private static function saveData($data)
    {
        file_put_contents(self::$cacheFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    private static function cleanOldEntries($data)
    {
        $currentTime = time();
        
        foreach ($data as $key => $entry) {
            // Eliminar entradas más antiguas que 24 horas
            if (($currentTime - $entry['first_request']) > 86400) {
                unset($data[$key]);
            }
        }
        
        return $data;
    }
}
