# Lumen API - Backend

API REST para el sitio web de Liceo Lumen construida con PHP y FlightPHP.

## 🚀 Instalación

### 1. Copiar FlightPHP

Este proyecto usa **FlightPHP**. Necesitas copiar la carpeta `flight/` de tu proyecto existente a esta ubicación:

```
lumen-api/
├── flight/           ← Copiar aquí
│   ├── core/
│   ├── net/
│   ├── template/
│   ├── util/
│   ├── autoload.php
│   ├── Engine.php
│   └── Flight.php
```

### 2. Configuración

Las credenciales de base de datos ya están configuradas en `env.php`:
- Host: 92.205.2.161
- Base de datos: lumen_academico_prod
- Usuario: liceo_lumen_prod

### 3. Iniciar servidor

```bash
cd C:/ruta/a/lumen-api
C:/xampp/php/php -S localhost:9999
```

### 4. Probar endpoints

**Test básico:**
```
http://localhost:9999/api/test
```

**Test de base de datos:**
```
http://localhost:9999/api/test/db
```

**Health check:**
```
http://localhost:9999/api/health
```

**Info de la API:**
```
http://localhost:9999/
```

## 📁 Estructura

```
lumen-api/
├── cache/              # Cache de rate limiting
├── flight/             # FlightPHP library
├── middleware/
│   ├── CorsMiddleware.php       # Control de CORS
│   └── RateLimitMiddleware.php  # Límite de peticiones
├── routes/
│   └── test.routes.php          # Rutas de test
├── services/
│   └── TestService.php          # Lógica de negocio
├── env.php             # Configuración
└── index.php           # Entry point
```

## 🔐 Seguridad

### CORS
Solo permite peticiones desde:
- `http://localhost:4200` (desarrollo)
- `https://liceolumen.com` (producción)

### Rate Limiting
- Máximo 3 peticiones por hora por IP
- Se aplica automáticamente

## 📝 Endpoints Disponibles

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/` | Información de la API |
| GET | `/api/test` | Test básico (sin BD) |
| GET | `/api/test/db` | Test de conexión BD |
| GET | `/api/health` | Health check |

## 🛠️ Próximos pasos

1. ✅ Test básico funcionando
2. ⏳ Crear tabla `contactos`
3. ⏳ Endpoint POST `/api/contacto`
4. ⏳ Integración con frontend

## 📞 Soporte

Desarrollado para Liceo Lumen - Jardín Infantil
