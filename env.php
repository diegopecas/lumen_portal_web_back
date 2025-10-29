<?php
/**
 * Configuración de entorno para Lumen API
 * Variables de conexión a base de datos
 */

// Configuración de base de datos
define('DB_HOST', '92.205.2.161');
define('DB_NAME', 'secretariadev');
define('DB_USERNAME', 'user_secretaria');
define('DB_PASSWORD', 'pw_secretaria');
define('DB_CHARSET', 'utf8mb4');

// Construir DSN
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET);

// Configuración de CORS - Dominios permitidos
define('ALLOWED_ORIGINS', [
    'http://localhost:4200',
    'https://liceolumen.com',
    'https://www.liceolumen.com'
]);

// Zona horaria
define('TIMEZONE', 'America/Bogota');
