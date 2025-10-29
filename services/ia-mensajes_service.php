<?php
class IaMensajes
{
    private static $cacheDir = __DIR__ . '/../cache/';
    private static $logFile = 'ia_mensajes_log.json';

    /**
     * Obtener la IP real del cliente
     */
    private static function obtenerIPCliente()
    {
        // Verificar diferentes headers para obtener la IP real
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Puede contener múltiples IPs, tomar la primera
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
     * Obtener mensaje personalizado para el usuario
     */
    public static function obtenerMensajePersonalizado()
    {
        try {
            $db = Flight::db();

            // Obtener datos del request
            $nombreUsuario = Flight::request()->data['nombre_usuario'] ?? 'Estudiante';
            
            // Obtener IP del cliente
            $ipCliente = self::obtenerIPCliente();

            error_log("=== INICIO GENERACIÓN MENSAJE IA ===");
            error_log("Nombre recibido del frontend: " . $nombreUsuario);
            error_log("IP del cliente: " . $ipCliente);

            // 1. Verificar y resetear contador si es un nuevo día
            self::verificarYResetearContador($db);

            // 2. Obtener configuración actual
            $config = self::obtenerConfiguracion($db);

            error_log("Configuración - Límite: " . $config['limite_diario']);
            error_log("Configuración - Generados hoy: " . $config['mensajes_generados_hoy']);
            error_log("Configuración - Estado: " . $config['estado_servicio']);

            // 3. Verificar si el servicio está activo
            if ($config['estado_servicio'] !== 'activo') {
                error_log("Servicio pausado/mantenimiento");
                return self::responderConFallback($nombreUsuario, 'servicio_pausado');
            }

            // 4. Verificar si se alcanzó el límite diario
            if ((int)$config['mensajes_generados_hoy'] >= (int)$config['limite_diario']) {
                error_log("Límite diario alcanzado");
                return self::responderConFallback($nombreUsuario, 'limite_alcanzado');
            }

            // 5. Verificar que existe API key
            if (empty($config['gemini_api_key'])) {
                error_log("API Key no configurada");
                return self::responderConFallback($nombreUsuario, 'api_key_faltante');
            }

            // 6. Generar mensaje con Gemini
            $mensajeGenerado = self::generarMensajeConGemini($nombreUsuario, $config['gemini_api_key']);

            if ($mensajeGenerado['success']) {
                // 7. Incrementar contador
                self::incrementarContador($db);

                // 8. Obtener stats actualizados
                $statsActualizados = self::obtenerConfiguracion($db);

                error_log("Mensaje generado exitosamente");
                error_log("=== FIN GENERACIÓN MENSAJE IA ===");

                Flight::json([
                    'success' => true,
                    'mensaje' => $mensajeGenerado['mensaje'],
                    'tipo' => $mensajeGenerado['tipo'],
                    'ip_cliente' => $ipCliente,
                    'stats' => [
                        'mensajes_usados_hoy' => (int)$statsActualizados['mensajes_generados_hoy'],
                        'disponibles' => (int)$statsActualizados['limite_diario'] - (int)$statsActualizados['mensajes_generados_hoy']
                    ]
                ]);
            } else {
                error_log("Error al generar con Gemini: " . $mensajeGenerado['error']);
                return self::responderConFallback($nombreUsuario, 'error_gemini');
            }
        } catch (Exception $e) {
            error_log("EXCEPCIÓN en obtenerMensajePersonalizado: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return self::responderConFallback($nombreUsuario ?? 'Estudiante', 'error_sistema');
        }
    }
    /**
     * Verificar si hoy es una fecha especial (cumpleaños, Navidad, Año Nuevo)
     */
    private static function verificarFechaEspecial()
    {
        try {
            $db = Flight::db();
            $hoy = date('Y-m-d');
            $mesHoy = date('m');
            $diaHoy = date('d');

            // 1. Verificar cumpleaños del usuario
            $usuarioId = Flight::request()->data['id_usuario'] ?? null;

            if ($usuarioId) {
                $stmt = $db->prepare("
                SELECT p.primer_nombre, p.fecha_nacimiento 
                FROM usuarios u 
                INNER JOIN personas p ON u.id_persona = p.id 
                WHERE u.id = ?
            ");
                $stmt->execute([$usuarioId]);
                $persona = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($persona && $persona['fecha_nacimiento']) {
                    $fechaNac = date('m-d', strtotime($persona['fecha_nacimiento']));
                    $hoyMesDia = date('m-d');

                    if ($fechaNac === $hoyMesDia) {
                        return [
                            'tipo' => 'cumpleaños',
                            'prompt' => "Crea un mensaje de cumpleaños muy especial y emotivo para {$persona['primer_nombre']} en su día especial. Hazlo personal, motivador y lleno de buenos deseos para este nuevo año de vida. Máximo 35 palabras. Usa emojis de celebración 🎂🎉. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional."
                        ];
                    }
                }
            }

            // 2. Verificar Navidad (24-25 diciembre)
            if ($mesHoy === '12' && ($diaHoy === '24' || $diaHoy === '25')) {
                return [
                    'tipo' => 'navidad',
                    'prompt' => "Crea un mensaje navideño cálido y especial para un estudiante. Que transmita paz, amor y buenos deseos para esta Navidad. Máximo 30 palabras. Usa emojis navideños 🎄✨. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional."
                ];
            }

            // 3. Verificar Año Nuevo (31 dic - 1 ene)
            if (($mesHoy === '12' && $diaHoy === '31') || ($mesHoy === '01' && $diaHoy === '01')) {
                return [
                    'tipo' => 'año_nuevo',
                    'prompt' => "Crea un mensaje inspirador de Año Nuevo para un estudiante. Que motive a establecer metas educativas y personales para este nuevo año. Máximo 30 palabras. Usa emojis de celebración 🎆🎊. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional."
                ];
            }

            return null;
        } catch (Exception $e) {
            error_log("Error verificando fecha especial: " . $e->getMessage());
            return null;
        }
    }
    /**
     * Generar mensaje usando Gemini API
     */
    private static function generarMensajeConGemini($nombreUsuario, $apiKey)
    {
        try {
            // Verificar si es una fecha especial
            $fechaEspecial = self::verificarFechaEspecial();

            if ($fechaEspecial) {
                $tipoSeleccionado = $fechaEspecial['tipo'];
                $promptPersonalizado = $fechaEspecial['prompt'];
            } else {
                $tiposContenido = [
                    'dato_curioso',
                    'noticia_educativa',
                    'mensaje_motivacional',
                    'chiste_educativo',
                    'frase_inspiradora'
                ];

                $tipoSeleccionado = $tiposContenido[array_rand($tiposContenido)];
                $promptPersonalizado = null;
            }

            $prompts = [
                'dato_curioso' => "Genera un dato curioso científico o histórico fascinante para un estudiante llamado {$nombreUsuario}. Personaliza el mensaje con su nombre. Máximo 30 palabras. Usa emojis relevantes. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'noticia_educativa' => "Comparte una noticia educativa o descubrimiento científico reciente interesante para {$nombreUsuario}. Personaliza con su nombre. Máximo 30 palabras. Usa emojis. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'mensaje_motivacional' => "Crea un mensaje motivacional poderoso y personalizado para {$nombreUsuario} sobre educación y superación personal. Máximo 30 palabras. Usa emojis inspiradores. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'chiste_educativo' => "Cuenta un chiste educativo inteligente y divertido para {$nombreUsuario}. Personaliza con su nombre. Máximo 30 palabras. Usa emojis divertidos. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional.",

                'frase_inspiradora' => "Comparte una frase inspiradora de un personaje histórico famoso adaptada para {$nombreUsuario}. Personaliza con su nombre. Máximo 30 palabras. Usa emojis. IMPORTANTE: Responde ÚNICAMENTE con el mensaje entre comillas dobles, sin texto adicional."
            ];

            // Usar prompt personalizado si existe, o el prompt normal
            $prompt = $promptPersonalizado ?? $prompts[$tipoSeleccionado];

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
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("Gemini HTTP Error: " . $httpCode);
                error_log("Response: " . $response);
                return [
                    'success' => false,
                    'error' => 'HTTP ' . $httpCode
                ];
            }

            $data = json_decode($response, true);

            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $respuestaCompleta = trim($data['candidates'][0]['content']['parts'][0]['text']);

                // Extraer SOLO lo que está entre comillas dobles
                if (preg_match('/"(.+?)"/s', $respuestaCompleta, $matches)) {
                    $mensajeGenerado = trim($matches[1]);
                } else {
                    // Si por alguna razón no tiene comillas, usar la respuesta completa
                    $mensajeGenerado = $respuestaCompleta;
                }

                return [
                    'success' => true,
                    'mensaje' => $mensajeGenerado,
                    'tipo' => $tipoSeleccionado
                ];
            } else {
                error_log("Formato de respuesta inesperado de Gemini");
                error_log("Response: " . print_r($data, true));
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
    private static function responderConFallback($nombreUsuario, $razon)
    {
        $mensajesFallback = [
            "¡Bienvenido {$nombreUsuario}! Hoy es un gran día para aprender algo nuevo 🌟",
            "{$nombreUsuario}, tu potencial es ilimitado. ¡A brillar! ✨",
            "Hola {$nombreUsuario}, que la curiosidad te guíe hoy 🚀",
            "{$nombreUsuario}, cada día es una oportunidad de crecer 🌱",
            "¡Excelente día {$nombreUsuario}! El conocimiento te espera 📚",
            "{$nombreUsuario}, eres capaz de lograr cosas increíbles 💪",
            "Que tengas un día lleno de descubrimientos {$nombreUsuario} 🔍",
            "{$nombreUsuario}, el aprendizaje es tu superpoder 🦸‍♂️"
        ];

        $mensajeSeleccionado = $mensajesFallback[array_rand($mensajesFallback)];
        
        // Obtener IP del cliente para fallback también
        $ipCliente = self::obtenerIPCliente();

        Flight::json([
            'success' => true,
            'mensaje' => $mensajeSeleccionado,
            'tipo' => 'fallback',
            'razon' => $razon,
            'ip_cliente' => $ipCliente
        ]);
    }

    /**
     * Obtener configuración desde la BD
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

            // Resetear contador
            $db->prepare("UPDATE configuracion_ia SET valor = '0' WHERE clave = 'mensajes_generados_hoy'")->execute();

            // Actualizar fecha
            $db->prepare("UPDATE configuracion_ia SET valor = ? WHERE clave = 'fecha_ultimo_reset'")
                ->execute([$fechaHoy]);
        }
    }

    /**
     * Incrementar contador de mensajes generados
     */
    private static function incrementarContador($db)
    {
        $db->prepare("UPDATE configuracion_ia SET valor = valor + 1 WHERE clave = 'mensajes_generados_hoy'")->execute();
    }

    /**
     * Guardar mensaje en log
     */
    private static function guardarEnLog($nombreUsuario, $tipo, $mensaje)
    {
        try {
            $logPath = self::$cacheDir . self::$logFile;

            // Crear directorio si no existe
            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0755, true);
            }

            // Cargar log existente o crear nuevo
            if (file_exists($logPath)) {
                $logData = json_decode(file_get_contents($logPath), true);
            } else {
                $logData = [
                    'fecha' => date('Y-m-d'),
                    'mensajes_generados' => 0,
                    'historial' => []
                ];
            }

            // Si es un nuevo día, resetear historial
            if ($logData['fecha'] !== date('Y-m-d')) {
                $logData = [
                    'fecha' => date('Y-m-d'),
                    'mensajes_generados' => 0,
                    'historial' => []
                ];
            }

            // Agregar nuevo mensaje
            $logData['historial'][] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'usuario' => $nombreUsuario,
                'tipo' => $tipo,
                'mensaje' => $mensaje
            ];

            $logData['mensajes_generados'] = count($logData['historial']);

            // Guardar log
            file_put_contents($logPath, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            error_log("Error guardando en log: " . $e->getMessage());
        }
    }

    /**
     * Obtener estadísticas (para admin)
     */
    public static function obtenerEstadisticas()
    {
        try {
            $db = Flight::db();
            $config = self::obtenerConfiguracion($db);

            $logPath = self::$cacheDir . self::$logFile;
            $logData = file_exists($logPath)
                ? json_decode(file_get_contents($logPath), true)
                : ['historial' => []];

            Flight::json([
                'success' => true,
                'configuracion' => $config,
                'log_hoy' => [
                    'total_mensajes' => count($logData['historial'] ?? []),
                    'ultimo_mensaje' => end($logData['historial']) ?: null
                ]
            ]);
        } catch (Exception $e) {
            Flight::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar configuración (para admin)
     */
    public static function actualizarConfiguracion()
    {
        try {
            $db = Flight::db();
            $clave = Flight::request()->data['clave'] ?? null;
            $valor = Flight::request()->data['valor'] ?? null;

            if (!$clave || $valor === null) {
                Flight::json(['error' => 'Parámetros inválidos'], 400);
                return;
            }

            $stmt = $db->prepare("UPDATE configuracion_ia SET valor = ? WHERE clave = ?");
            $stmt->execute([$valor, $clave]);

            Flight::json([
                'success' => true,
                'message' => 'Configuración actualizada'
            ]);
        } catch (Exception $e) {
            Flight::json(['error' => $e->getMessage()], 500);
        }
    }
}
