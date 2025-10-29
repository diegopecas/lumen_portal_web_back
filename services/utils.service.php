<?php
/**
 * Servicio de utilidades transversales
 * Funciones reutilizables en toda la aplicación
 */
class UtilsService
{
    /**
     * Obtener la IP real del cliente
     * Maneja proxies, balanceadores de carga y CDNs
     */
    public static function obtenerIPCliente(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // Si tiene múltiples IPs (por proxies), tomar la primera
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                // Validar que sea una IP válida
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Obtener User Agent del cliente
     */
    public static function obtenerUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
    
    /**
     * Obtener valor de configuración
     */
    public static function obtenerConfiguracion(string $clave, $default = null)
    {
        try {
            $db = Flight::db();
            $stmt = $db->prepare("SELECT valor, tipo FROM configuracion_portal WHERE clave = ?");
            $stmt->execute([$clave]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                return $default;
            }
            
            // Convertir según el tipo
            switch ($config['tipo']) {
                case 'numero':
                    return (int)$config['valor'];
                case 'boolean':
                    return (bool)$config['valor'];
                case 'json':
                    return json_decode($config['valor'], true);
                default:
                    return $config['valor'];
            }
            
        } catch (Exception $e) {
            error_log("Error obteniendo configuración '$clave': " . $e->getMessage());
            return $default;
        }
    }
    
    /**
     * Actualizar valor de configuración
     */
    public static function actualizarConfiguracion(string $clave, $valor): bool
    {
        try {
            $db = Flight::db();
            
            // Si el valor es array/object, convertir a JSON
            if (is_array($valor) || is_object($valor)) {
                $valor = json_encode($valor);
            }
            
            $stmt = $db->prepare("UPDATE configuracion_portal SET valor = ? WHERE clave = ?");
            $stmt->execute([$valor, $clave]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error actualizando configuración '$clave': " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Sanitizar texto para prevenir XSS
     */
    public static function sanitizarTexto(string $texto): string
    {
        return htmlspecialchars(trim($texto), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validar email
     */
    public static function validarEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validar teléfono colombiano
     */
    public static function validarTelefonoColombia(string $telefono): bool
    {
        // Remover espacios y caracteres especiales
        $telefono = preg_replace('/[^0-9]/', '', $telefono);
        
        // Debe tener entre 7 y 10 dígitos
        $longitud = strlen($telefono);
        return $longitud >= 7 && $longitud <= 10;
    }
    
    /**
     * Formatear fecha a zona horaria de Colombia
     */
    public static function formatearFechaColombia(string $fecha, string $formato = 'Y-m-d H:i:s'): string
    {
        $dt = new DateTime($fecha, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Bogota'));
        return $dt->format($formato);
    }
    
    /**
     * Verificar rate limit por IP
     */
    public static function verificarRateLimit(string $tabla, string $ip, int $limiteHoras = 1, int $maxIntentos = 3): bool
    {
        try {
            $db = Flight::db();
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as total 
                FROM $tabla 
                WHERE ip_address = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$ip, $limiteHoras]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)$result['total'] < $maxIntentos;
            
        } catch (Exception $e) {
            error_log("Error verificando rate limit: " . $e->getMessage());
            return true; // En caso de error, permitir
        }
    }
    
    /**
     * Generar token único
     */
    public static function generarToken(int $longitud = 32): string
    {
        return bin2hex(random_bytes($longitud / 2));
    }
    
    /**
     * Enviar respuesta JSON
     */
    public static function responderJSON(array $data, int $statusCode = 200): void
    {
        Flight::response()->status($statusCode);
        Flight::response()->header('Content-Type', 'application/json; charset=utf-8');
        Flight::json($data);
    }
    
    /**
     * Log de actividad
     */
    public static function log(string $mensaje, string $nivel = 'info', array $contexto = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ip = self::obtenerIPCliente();
        $contextoStr = !empty($contexto) ? ' | Contexto: ' . json_encode($contexto) : '';
        
        $logMessage = "[{$timestamp}] [{$nivel}] IP: {$ip} | {$mensaje}{$contextoStr}";
        error_log($logMessage);
    }
}