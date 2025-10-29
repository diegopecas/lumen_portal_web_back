<?php
/**
 * Servicio de mensajes IA para visitantes del portal de Lumen
 * Este servicio genera mensajes para visitantes anónimos (sin login)
 */
class PortalMensajes
{
    /**
     * Obtener la IP real del cliente
     */
    private static function obtenerIPCliente()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        return trim($ip);
    }

    /**
     * Obtener mensaje para visitante del portal
     */
    public static function obtenerMensajeVisitante()
    {
        try {
            $db = Flight::db();
            $ipCliente = self::obtenerIPCliente();

            error_log("=== MENSAJE PORTAL - IP: " . $ipCliente . " ===");

            // Verificar y resetear contador diario
            self::verificarYResetearContador($db);

            // Obtener configuración
            $config = self::obtenerConfiguracion($db);

            // Verificar estado del servicio
            if ($config['estado_servicio'] !== 'activo') {
                error_log("Servicio pausado");
                return self::responderConFallback($ipCliente, 'servicio_pausado');
            }

            // Verificar límite diario
            if ((int)$config['mensajes_generados_hoy'] >= (int)$config['limite_diario']) {
                error_log("Límite diario alcanzado");
                return self::responderConFallback($ipCliente, 'limite_alcanzado');
            }

            // Verificar API key
            if (empty($config['gemini_api_key'])) {
                error_log("API Key no configurada");
                return self::responderConFallback($ipCliente, 'api_key_faltante');
            }

            // Generar mensaje con Gemini
            $mensajeGenerado = self::generarMensajeConGemini($config['gemini_api_key']);

            if ($mensajeGenerado['success']) {
                self::incrementarContador($db);

                Flight::json([
                    'success' => true,
                    'mensaje' => $mensajeGenerado['mensaje'],
                    'tipo' => $mensajeGenerado['tipo'],
                    'ip_cliente' => $ipCliente
                ]);
            } else {
                error_log("Error generando con Gemini: " . $mensajeGenerado['error']);
                return self::responderConFallback($ipCliente, 'error_gemini');
            }

        } catch (Exception $e) {
            error_log("ERROR en obtenerMensajeVisitante: " . $e->getMessage());
            return self::responderConFallback(self::obtenerIPCliente(), 'error_sistema');
        }
    }

    /**
     * Generar mensaje usando Gemini API para visitantes
     */
    private static function generarMensajeConGemini($apiKey)
    {
        try {
            // Tipos de mensajes para visitantes del jardín infantil
            $tiposContenido = [
                'dato_curioso',
                'tip_educativo',
                'mensaje_motivacional',
                'frase_inspiradora',
                'consejo_crianza'
            ];

            $tipoSeleccionado = $tiposContenido[array_rand($tiposContenido)];

            $prompts = [
                'dato_curioso' => "Genera un dato curioso fascinante sobre educación infantil o desarrollo de niños de 0 a 6 años que sorprenda a padres visitantes de un jardín infantil. Máximo 30 palabras. Usa emojis relevantes. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'tip_educativo' => "Comparte un tip educativo práctico y útil para padres con niños pequeños (0-6 años) sobre estimulación temprana, aprendizaje o desarrollo. Máximo 30 palabras. Usa emojis. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'mensaje_motivacional' => "Crea un mensaje motivacional breve y poderoso para padres visitantes sobre la importancia de la educación temprana y el desarrollo de sus hijos. Máximo 30 palabras. Usa emojis inspiradores. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'frase_inspiradora' => "Comparte una frase inspiradora breve de un pedagogo o experto en educación infantil sobre crianza, aprendizaje temprano o desarrollo infantil. Máximo 30 palabras. Usa emojis. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'consejo_crianza' => "Da un consejo breve y práctico de crianza positiva para padres con niños de 0 a 6 años, enfocado en desarrollo emocional, social o cognitivo. Máximo 30 palabras. Usa emojis relevantes. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional."
            ];

            $prompt = $prompts[$tipoSeleccionado];

            $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

            $body = json_encode([
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("Gemini HTTP error: " . $httpCode);
                error_log("Response: " . $response);
                return [
                    'success' => false,
                    'error' => 'Error HTTP ' . $httpCode
                ];
            }

            $data = json_decode($response, true);

            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $mensaje = $data['candidates'][0]['content']['parts'][0]['text'];
                $mensaje = trim($mensaje, '"');

                return [
                    'success' => true,
                    'mensaje' => $mensaje,
                    'tipo' => $tipoSeleccionado
                ];
            } else {
                error_log("Formato de respuesta inesperado de Gemini");
                return [
                    'success' => false,
                    'error' => 'Formato de respuesta inválido'
                ];
            }

        } catch (Exception $e) {
            error_log("Error en generarMensajeConGemini: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Responder con mensaje de fallback
     */
    private static function responderConFallback($ipCliente, $razon)
    {
        $mensajesFallback = [
            "¡Bienvenido a Lumen! 🌟 Cada día es una oportunidad para descubrir el potencial de tu hijo.",
            "La educación temprana es la base del futuro. 💡 Gracias por visitarnos.",
            "En Lumen creemos en el poder del juego y la exploración. 🎨 ¡Conoce nuestros programas!",
            "Los primeros años son mágicos. ✨ Acompáñanos en este viaje educativo.",
            "Tu hijo merece la mejor educación inicial. 🌱 Descubre cómo podemos ayudarte.",
            "Cada niño es único y especial. 🦋 En Lumen potenciamos sus talentos.",
            "La curiosidad es el motor del aprendizaje. 🚀 ¡Exploremos juntos!",
            "Educación con amor y excelencia. 💚 Bienvenido a la familia Lumen."
        ];

        $mensajeSeleccionado = $mensajesFallback[array_rand($mensajesFallback)];

        Flight::json([
            'success' => true,
            'mensaje' => $mensajeSeleccionado,
            'tipo' => 'fallback',
            'razon' => $razon,
            'ip_cliente' => $ipCliente
        ]);
    }

    /**
     * Obtener configuración desde la BD (usa la misma tabla que el otro proyecto)
     */
    private static function obtenerConfiguracion($db)
    {
        $stmt = $db->prepare("SELECT clave, valor FROM configuracion_ia");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $config = [];
        foreach ($rows as $row) {
            $config[$row['clave']] = $row['valor'];
        }

        return $config;
    }

    /**
     * Verificar y resetear contador si es un nuevo día
     */
    private static function verificarYResetearContador($db)
    {
        $stmt = $db->prepare("SELECT valor FROM configuracion_ia WHERE clave = 'fecha_ultimo_reset'");
        $stmt->execute();
        $fechaUltimoReset = $stmt->fetchColumn();

        $fechaHoy = date('Y-m-d');

        if ($fechaUltimoReset !== $fechaHoy) {
            error_log("Reseteando contador - Nuevo día detectado");
            $db->prepare("UPDATE configuracion_ia SET valor = '0' WHERE clave = 'mensajes_generados_hoy'")->execute();
            $db->prepare("UPDATE configuracion_ia SET valor = ? WHERE clave = 'fecha_ultimo_reset'")->execute([$fechaHoy]);
        }
    }

    /**
     * Incrementar contador de mensajes generados
     */
    private static function incrementarContador($db)
    {
        $db->prepare("UPDATE configuracion_ia SET valor = valor + 1 WHERE clave = 'mensajes_generados_hoy'")->execute();
    }
}
