# BUXDEV API

API independiente creada con Laravel 13 y PHP 8.3+. Esta iteración contiene únicamente `GET /health`.

## Requisitos

PHP 8.3+ con las extensiones requeridas por Laravel y PHPUnit, incluyendo PDO MySQL, mbstring, DOM, XML y XMLWriter; Composer 2. MySQL/MariaDB cuando se necesite persistencia. El health check y sus tests no consultan la base de datos.

## Inicio local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8000
```

Configura las credenciales locales exclusivamente en `.env`. Usa `DB_CONNECTION=mysql` para MySQL o `DB_CONNECTION=mariadb` para MariaDB. No hay migraciones que ejecutar.

```bash
curl -i http://127.0.0.1:8000/health
```

Respuesta HTTP 200:

```json
{"status":"ok","service":"buxdev-api"}
```

## Validación

```bash
composer test
vendor/bin/pint --test
composer validate --strict
php artisan route:list
```

Las rutas se registran en `routes/api.php` sin prefijo para exponer exactamente `/health`. El controlador está en `app/Http/Controllers/Api/V1`. Los errores HTTP se devuelven como JSON incluso sin cabecera `Accept`. CORS está deshabilitado y no hay autenticación, usuarios, rutas de almacenamiento ni frontend.

## Configuración de producción

Define `APP_ENV=production`, `APP_DEBUG=false` y una `APP_KEY` propia en el entorno privado. Mantén `APP_DEBUG=false` para evitar detalles internos y trazas en las respuestas. `.env.example` no incluye secretos y los archivos `.env` están ignorados por Git.

El punto de entrada elimina `X-Powered-By`; configura además `expose_php=Off` en PHP y desactiva la divulgación de versiones en el servidor web. El document root debe ser `public/`. No se incluye ni se ejecuta ningún despliegue.
