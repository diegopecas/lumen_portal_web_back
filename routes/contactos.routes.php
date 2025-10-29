<?php
/**
 * Rutas para contactos del portal de Lumen
 */

// Crear nuevo contacto
Flight::route('POST /api/contactos/contactos', [ContactosService::class, 'crearContacto']);

// Obtener catálogos para el formulario
Flight::route('GET /api/contactos/catalogos', [ContactosService::class, 'obtenerCatalogos']);

// Webhook de Calendly
Flight::route('POST /api/contactos/webhook-calendly', [ContactosService::class, 'webhookCalendly']);