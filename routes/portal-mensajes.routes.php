<?php
/**
 * Rutas para mensajes del portal de Lumen
 */

// Obtener mensaje para visitante (NO requiere login)
Flight::route('GET /api/portal/mensaje-visitante', [PortalMensajes::class, 'obtenerMensajeVisitante']);
