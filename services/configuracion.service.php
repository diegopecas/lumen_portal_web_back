<?php
/**
 * Servicio centralizado de Configuración
 * Maneja toda la configuración del portal desde la BD
 */
class ConfiguracionService
{
    /**
     * Obtener valor de configuración por clave
     * 
     * @param string $clave La clave de configuración
     * @param mixed $default Valor por defecto si no existe
     * @return mixed El valor de la configuración
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
            return self::convertirValorPorTipo($config['valor'], $config['tipo']);
            
        } catch (Exception $e) {
            UtilsService::log("Error obteniendo configuración '$clave': " . $e->getMessage(), 'error');
            return $default;
        }
    }
    
    /**
     * Obtener múltiples configuraciones por categoría
     * 
     * @param string $categoria Categoría de configuraciones
     * @return array Array asociativo [clave => valor]
     */
    public static function obtenerConfiguracionesPorCategoria(string $categoria): array
    {
        try {
            $db = Flight::db();
            $stmt = $db->prepare("SELECT clave, valor, tipo FROM configuracion_portal WHERE categoria = ?");
            $stmt->execute([$categoria]);
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $resultado = [];
            foreach ($configs as $config) {
                $resultado[$config['clave']] = self::convertirValorPorTipo($config['valor'], $config['tipo']);
            }
            
            return $resultado;
            
        } catch (Exception $e) {
            UtilsService::log("Error obteniendo configuraciones de categoría '$categoria': " . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * Obtener todas las configuraciones
     * 
     * @return array Array asociativo [clave => valor]
     */
    public static function obtenerTodasConfiguraciones(): array
    {
        try {
            $db = Flight::db();
            $stmt = $db->query("SELECT clave, valor, tipo FROM configuracion_portal");
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $resultado = [];
            foreach ($configs as $config) {
                $resultado[$config['clave']] = self::convertirValorPorTipo($config['valor'], $config['tipo']);
            }
            
            return $resultado;
            
        } catch (Exception $e) {
            UtilsService::log("Error obteniendo todas las configuraciones: " . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * Actualizar valor de configuración
     * 
     * @param string $clave La clave de configuración
     * @param mixed $valor El nuevo valor
     * @return bool True si se actualizó correctamente
     */
    public static function actualizarConfiguracion(string $clave, $valor): bool
    {
        try {
            $db = Flight::db();
            
            // Si el valor es array/object, convertir a JSON
            if (is_array($valor) || is_object($valor)) {
                $valor = json_encode($valor);
            }
            
            $stmt = $db->prepare("UPDATE configuracion_portal SET valor = ?, updated_at = NOW() WHERE clave = ?");
            $stmt->execute([$valor, $clave]);
            
            UtilsService::log("Configuración actualizada", 'info', ['clave' => $clave]);
            
            return true;
            
        } catch (Exception $e) {
            UtilsService::log("Error actualizando configuración '$clave': " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Crear nueva configuración
     * 
     * @param string $clave Clave única
     * @param mixed $valor Valor de la configuración
     * @param string $tipo Tipo: texto, numero, boolean, json, url
     * @param string $categoria Categoría
     * @param string $descripcion Descripción
     * @return bool True si se creó correctamente
     */
    public static function crearConfiguracion(
        string $clave, 
        $valor, 
        string $tipo = 'texto', 
        string $categoria = 'general', 
        string $descripcion = ''
    ): bool {
        try {
            $db = Flight::db();
            
            // Si el valor es array/object, convertir a JSON
            if (is_array($valor) || is_object($valor)) {
                $valor = json_encode($valor);
            }
            
            $stmt = $db->prepare("
                INSERT INTO configuracion_portal (clave, valor, tipo, categoria, descripcion) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$clave, $valor, $tipo, $categoria, $descripcion]);
            
            UtilsService::log("Configuración creada", 'info', ['clave' => $clave]);
            
            return true;
            
        } catch (Exception $e) {
            UtilsService::log("Error creando configuración '$clave': " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Verificar si existe una configuración
     * 
     * @param string $clave La clave a verificar
     * @return bool True si existe
     */
    public static function existeConfiguracion(string $clave): bool
    {
        try {
            $db = Flight::db();
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM configuracion_portal WHERE clave = ?");
            $stmt->execute([$clave]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)$result['total'] > 0;
            
        } catch (Exception $e) {
            UtilsService::log("Error verificando existencia de configuración '$clave': " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Convertir valor según su tipo
     * 
     * @param mixed $valor El valor a convertir
     * @param string $tipo El tipo de dato
     * @return mixed Valor convertido
     */
    private static function convertirValorPorTipo($valor, string $tipo)
    {
        switch ($tipo) {
            case 'numero':
                return is_numeric($valor) ? (strpos($valor, '.') !== false ? (float)$valor : (int)$valor) : 0;
            case 'boolean':
                return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($valor, true);
            case 'texto':
            case 'url':
            default:
                return $valor;
        }
    }
    
    /**
     * Endpoint público para obtener configuraciones del portal (solo las públicas)
     * Para uso desde el frontend
     */
    public static function obtenerConfiguracionesPublicas()
    {
        try {
            // Configuraciones seguras para exponer al frontend
            $configuracionesPublicas = [
                'google_analytics_id',
                'calendly_url',
                'honeypot_enabled'
            ];
            
            $resultado = [];
            foreach ($configuracionesPublicas as $clave) {
                $valor = self::obtenerConfiguracion($clave);
                if ($valor !== null) {
                    $resultado[$clave] = $valor;
                }
            }
            
            UtilsService::responderJSON([
                'success' => true,
                'configuraciones' => $resultado
            ]);
            
        } catch (Exception $e) {
            UtilsService::log("Error obteniendo configuraciones públicas: " . $e->getMessage(), 'error');
            UtilsService::responderJSON([
                'success' => false,
                'message' => 'Error al obtener configuraciones'
            ], 500);
        }
    }
}