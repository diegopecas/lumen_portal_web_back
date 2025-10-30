<?php

// Ruta para obtener configuraciones públicas (para el frontend)
Flight::route('GET /api/configuraciones/publicas', ['ConfiguracionService', 'obtenerConfiguracionesPublicas']);