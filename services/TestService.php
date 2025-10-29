<?php
/**
 * Service de Test
 * Servicio básico para verificar que el API funciona
 */

class TestService
{
    public static function getHelloWorld()
    {
        $response = [
            'success' => true,
            'message' => 'Hola Mundo desde Lumen API',
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => TIMEZONE,
            'version' => '1.0.0'
        ];
        
        Flight::json($response);
    }
    
    public static function testDatabase()
    {
        try {
            $db = Flight::db();
            
            // Probar conexión con una consulta simple
            $stmt = $db->query("SELECT 1 as test, NOW() as server_time");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            Flight::json([
                'success' => true,
                'message' => 'Conexión a base de datos exitosa',
                'database' => DB_NAME,
                'host' => DB_HOST,
                'test_result' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (PDOException $e) {
            Flight::json([
                'success' => false,
                'error' => 'Error de conexión a base de datos',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}