<?php

// Ruta para obtener configuraciones públicas (para el frontend)
Flight::route('GET /api/configuraciones/publicas', ['ConfiguracionService', 'obtenerConfiguracionesPublicas']);

// Ruta para obtener configuraciones de contacto
Flight::route('GET /api/configuraciones/contacto', ['ConfiguracionService', 'obtenerConfiguracionesContacto']);