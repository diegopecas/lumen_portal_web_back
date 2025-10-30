<?php
/**
 * Servicio de Contactos para el portal de Lumen
 */
class ContactosService
{
    /**
     * Crear nuevo contacto desde el formulario del portal
     */
    public static function crearContacto()
    {
        try {
            $db = Flight::db();
            
            // Validar datos recibidos
            $datos = Flight::request()->data;
            
            $errores = self::validarDatosContacto($datos);
            if (!empty($errores)) {
                UtilsService::responderJSON([
                    'success' => false,
                    'errores' => $errores
                ], 400);
                return;
            }
            
            // Verificar honeypot usando ConfiguracionService
            $honeypotEnabled = ConfiguracionService::obtenerConfiguracion('honeypot_enabled', true);
            if ($honeypotEnabled && !empty($datos->honeypot)) {
                UtilsService::log("Posible spam detectado - Honeypot lleno", 'warning');
                UtilsService::responderJSON([
                    'success' => false,
                    'message' => 'Error al procesar solicitud'
                ], 400);
                return;
            }
            
            // Obtener IP y User Agent usando UtilsService
            $ipCliente = UtilsService::obtenerIPCliente();
            $userAgent = UtilsService::obtenerUserAgent();
            
            // Verificar rate limit por IP usando ConfiguracionService
            $limitePorHora = ConfiguracionService::obtenerConfiguracion('contacto_limite_por_hora', 3);
            if (!UtilsService::verificarRateLimit('contactos', $ipCliente, 1, $limitePorHora)) {
                UtilsService::log("Rate limit excedido para IP: $ipCliente", 'warning');
                UtilsService::responderJSON([
                    'success' => false,
                    'message' => 'Has excedido el límite de solicitudes. Por favor intenta más tarde.'
                ], 429);
                return;
            }
            
            // Sanitizar datos
            $nombrePadre = UtilsService::sanitizarTexto($datos->nombre_padre);
            $email = strtolower(trim($datos->email));
            $telefono = UtilsService::sanitizarTexto($datos->telefono);
            $mensaje = UtilsService::sanitizarTexto($datos->mensaje);
            $comoConocioDetalle = !empty($datos->como_conocio_detalle) ? UtilsService::sanitizarTexto($datos->como_conocio_detalle) : null;
            
            // Insertar contacto
            $stmt = $db->prepare("
                INSERT INTO contactos (
                    nombre_padre,
                    email,
                    telefono,
                    edad_nino,
                    mensaje,
                    como_conocio_detalle,
                    id_tipo_consulta,
                    id_como_conocio,
                    id_programa_interes,
                    id_estado,
                    ip_address,
                    user_agent,
                    honeypot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
            ");
            
            $edadNino = !empty($datos->edad_nino) ? (int)$datos->edad_nino : null;
            $programaInteres = !empty($datos->id_programa_interes) ? (int)$datos->id_programa_interes : null;
            
            $stmt->execute([
                $nombrePadre,
                $email,
                $telefono,
                $edadNino,
                $mensaje,
                $comoConocioDetalle,
                (int)$datos->id_tipo_consulta,
                (int)$datos->id_como_conocio,
                $programaInteres,
                $ipCliente,
                $userAgent,
                $datos->honeypot ?? ''
            ]);
            
            $contactoId = $db->lastInsertId();
            
            UtilsService::log("Nuevo contacto creado", 'info', [
                'contacto_id' => $contactoId,
                'email' => $email
            ]);
            
            // Obtener URL de Calendly usando ConfiguracionService
            $calendlyUrl = ConfiguracionService::obtenerConfiguracion('calendly_url');
            
            UtilsService::responderJSON([
                'success' => true,
                'message' => 'Contacto registrado exitosamente',
                'contacto_id' => $contactoId,
                'email' => $email,
                'calendly_url' => $calendlyUrl
            ]);
            
        } catch (Exception $e) {
            UtilsService::log("Error en crearContacto: " . $e->getMessage(), 'error');
            UtilsService::responderJSON([
                'success' => false,
                'message' => 'Error al procesar la solicitud'
            ], 500);
        }
    }
    
    /**
     * Webhook de Calendly - recibe notificaciones de eventos
     */
    public static function webhookCalendly()
    {
        try {
            $db = Flight::db();
            
            // Leer el payload JSON
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);
            
            UtilsService::log("Webhook Calendly recibido", 'info', [
                'payload_size' => strlen($payload)
            ]);
            
            if (!isset($data['event']) || !isset($data['payload'])) {
                UtilsService::log("Webhook Calendly - formato inválido", 'warning');
                UtilsService::responderJSON(['success' => false], 400);
                return;
            }
            
            $event = $data['event'];
            $payloadData = $data['payload'];
            
            // Procesar según el tipo de evento
            switch ($event) {
                case 'invitee.created':
                    self::procesarCitaCreada($db, $payloadData);
                    break;
                    
                case 'invitee.canceled':
                    self::procesarCitaCancelada($db, $payloadData);
                    break;
                    
                default:
                    UtilsService::log("Evento Calendly no manejado: $event", 'info');
            }
            
            UtilsService::responderJSON(['success' => true]);
            
        } catch (Exception $e) {
            UtilsService::log("Error en webhookCalendly: " . $e->getMessage(), 'error');
            UtilsService::responderJSON(['success' => false], 500);
        }
    }
    
    /**
     * Procesar cita creada en Calendly
     */
    private static function procesarCitaCreada($db, $payload)
    {
        try {
            $email = $payload['email'] ?? null;
            $name = $payload['name'] ?? null;
            $eventUri = $payload['event'] ?? null;
            $inviteeUri = $payload['uri'] ?? null;
            $scheduledEvent = $payload['scheduled_event'] ?? [];
            $eventType = $scheduledEvent['event_type'] ?? null;
            $startTime = $scheduledEvent['start_time'] ?? null;
            
            if (!$email) {
                UtilsService::log("Webhook Calendly - email no encontrado", 'warning');
                return;
            }
            
            // Buscar contacto por email (el más reciente)
            $stmt = $db->prepare("
                SELECT id 
                FROM contactos 
                WHERE email = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([strtolower($email)]);
            $contacto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$contacto) {
                UtilsService::log("Webhook Calendly - contacto no encontrado", 'warning', [
                    'email' => $email
                ]);
                return;
            }
            
            // Convertir fecha de Calendly a formato MySQL (Colombia)
            $fechaCita = null;
            if ($startTime) {
                $fechaCita = UtilsService::formatearFechaColombia($startTime);
            }
            
            // Actualizar contacto con datos de Calendly
            $stmt = $db->prepare("
                UPDATE contactos 
                SET fecha_cita = ?,
                    calendly_event_uri = ?,
                    calendly_invitee_uri = ?,
                    calendly_event_type = ?,
                    cita_estado = 'confirmada',
                    id_estado = 3
                WHERE id = ?
            ");
            
            $stmt->execute([
                $fechaCita,
                $eventUri,
                $inviteeUri,
                $eventType,
                $contacto['id']
            ]);
            
            UtilsService::log("Cita agendada exitosamente", 'info', [
                'contacto_id' => $contacto['id'],
                'fecha_cita' => $fechaCita
            ]);
            
        } catch (Exception $e) {
            UtilsService::log("Error en procesarCitaCreada: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Procesar cita cancelada en Calendly
     */
    private static function procesarCitaCancelada($db, $payload)
    {
        try {
            $inviteeUri = $payload['uri'] ?? null;
            
            if (!$inviteeUri) {
                UtilsService::log("Webhook Calendly - invitee URI no encontrado", 'warning');
                return;
            }
            
            $stmt = $db->prepare("
                UPDATE contactos 
                SET cita_estado = 'cancelada'
                WHERE calendly_invitee_uri = ?
            ");
            
            $stmt->execute([$inviteeUri]);
            
            UtilsService::log("Cita cancelada", 'info', [
                'invitee_uri' => $inviteeUri
            ]);
            
        } catch (Exception $e) {
            UtilsService::log("Error en procesarCitaCancelada: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Obtener catálogos para el formulario
     */
    public static function obtenerCatalogos()
    {
        try {
            $db = Flight::db();
            
            $catalogos = [
                'tipos_consulta' => [],
                'tipos_como_conocio' => [],
                'programas_interes' => []
            ];
            
            // Tipos de consulta
            $stmt = $db->query("SELECT id, nombre FROM tipos_consulta WHERE activo = 1 ORDER BY orden");
            $catalogos['tipos_consulta'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Tipos cómo conoció (con pide_detalle y placeholder)
            $stmt = $db->query("
                SELECT id, nombre, pide_detalle, placeholder_detalle 
                FROM tipos_como_conocio 
                WHERE activo = 1 
                ORDER BY id
            ");
            $catalogos['tipos_como_conocio'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Programas de interés
            $stmt = $db->query("SELECT id, nombre, descripcion FROM programas_interes WHERE activo = 1 ORDER BY orden");
            $catalogos['programas_interes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            UtilsService::responderJSON([
                'success' => true,
                'catalogos' => $catalogos
            ]);
            
        } catch (Exception $e) {
            UtilsService::log("Error en obtenerCatalogos: " . $e->getMessage(), 'error');
            UtilsService::responderJSON([
                'success' => false,
                'message' => 'Error al obtener catálogos'
            ], 500);
        }
    }
    
    /**
     * Validar datos del contacto
     */
    private static function validarDatosContacto($datos)
    {
        $errores = [];
        
        if (empty($datos->nombre_padre) || strlen(trim($datos->nombre_padre)) < 3) {
            $errores[] = 'El nombre es requerido (mínimo 3 caracteres)';
        }
        
        if (empty($datos->email) || !UtilsService::validarEmail($datos->email)) {
            $errores[] = 'Email inválido';
        }
        
        if (empty($datos->telefono) || !UtilsService::validarTelefonoColombia($datos->telefono)) {
            $errores[] = 'Teléfono inválido (debe tener entre 7 y 10 dígitos)';
        }
        
        if (empty($datos->mensaje) || strlen(trim($datos->mensaje)) < 10) {
            $errores[] = 'El mensaje es requerido (mínimo 10 caracteres)';
        }
        
        if (empty($datos->id_tipo_consulta)) {
            $errores[] = 'Debe seleccionar un tipo de consulta';
        }
        
        if (empty($datos->id_como_conocio)) {
            $errores[] = 'Debe indicar cómo nos conoció';
        }
        
        return $errores;
    }
}