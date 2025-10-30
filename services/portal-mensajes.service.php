<?php
/**
 * Servicio de mensajes IA para visitantes del portal de Lumen
 * REFACTORIZADO para usar ConfiguracionService
 */
class PortalMensajes
{
    /**
     * Obtener mensaje para visitante del portal
     */
    public static function obtenerMensajeVisitante()
    {
        try {
            $db = Flight::db();
            $ipCliente = UtilsService::obtenerIPCliente();

            error_log("=== MENSAJE PORTAL - IP: " . $ipCliente . " ===");

            // Verificar y resetear contador diario
            self::verificarYResetearContador($db);

            // Obtener configuración usando ConfiguracionService
            $estadoServicio = ConfiguracionService::obtenerConfiguracion('estado_servicio', 'pausado');
            $limiteDiario = ConfiguracionService::obtenerConfiguracion('limite_diario', 100);
            $mensajesGeneradosHoy = ConfiguracionService::obtenerConfiguracion('mensajes_generados_hoy', 0);
            $geminiApiKey = ConfiguracionService::obtenerConfiguracion('gemini_api_key');

            // Verificar estado del servicio
            if ($estadoServicio !== 'activo') {
                error_log("Servicio pausado");
                return self::responderConFallback($ipCliente, 'servicio_pausado');
            }

            // Verificar límite diario
            if ((int)$mensajesGeneradosHoy >= (int)$limiteDiario) {
                error_log("Límite diario alcanzado");
                return self::responderConFallback($ipCliente, 'limite_alcanzado');
            }

            // Verificar API key
            if (empty($geminiApiKey)) {
                error_log("API Key no configurada");
                return self::responderConFallback($ipCliente, 'api_key_faltante');
            }

            // Generar mensaje con Gemini
            $mensajeGenerado = self::generarMensajeConGemini($geminiApiKey);

            if ($mensajeGenerado['success']) {
                self::incrementarContador();

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
            return self::responderConFallback(UtilsService::obtenerIPCliente(), 'error_sistema');
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
     * Verificar y resetear contador si es un nuevo día
     */
    private static function verificarYResetearContador($db)
    {
        $fechaUltimoReset = ConfiguracionService::obtenerConfiguracion('fecha_ultimo_reset');
        $fechaHoy = date('Y-m-d');

        if ($fechaUltimoReset !== $fechaHoy) {
            error_log("Reseteando contador - Nuevo día detectado");
            ConfiguracionService::actualizarConfiguracion('mensajes_generados_hoy', '0');
            ConfiguracionService::actualizarConfiguracion('fecha_ultimo_reset', $fechaHoy);
        }
    }

    /**
     * Incrementar contador de mensajes generados
     */
    private static function incrementarContador()
    {
        $mensajesActuales = ConfiguracionService::obtenerConfiguracion('mensajes_generados_hoy', 0);
        ConfiguracionService::actualizarConfiguracion('mensajes_generados_hoy', $mensajesActuales + 1);
    }
}