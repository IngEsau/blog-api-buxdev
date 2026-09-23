#!/usr/bin/env bash
# FTPS explícito desde un commit limpio. Nunca ejecuta comandos remotos.
set +x
set +v
set -Eeuo pipefail
umask 077

readonly PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
readonly PHP_IMAGE='buxdev-api-php:8.3'
readonly MYSQL_IMAGE='mysql:8.0'
readonly REMOTE_ROOT='/'
readonly DEPLOY_ENV="$PROJECT_ROOT/.env.deploy"
readonly DEPLOY_LOG_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/buxdev-api-deploy"
TEMP_ROOT=''
DATABASE_CONTAINER=''
LFTP_PID=''

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$LFTP_PID" ]] && kill -0 "$LFTP_PID" >/dev/null 2>&1; then
        kill "$LFTP_PID" >/dev/null 2>&1 || true
        wait "$LFTP_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n "$DATABASE_CONTAINER" ]]; then
        docker rm --force "$DATABASE_CONTAINER" >/dev/null 2>&1 || true
    fi
    if [[ -n "$TEMP_ROOT" ]]; then
        rm -rf -- "$TEMP_ROOT"
    fi
    exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'printf "Error: proceso interrumpido; no se confirma un despliegue completo.\n" >&2' ERR

[[ $# -eq 0 ]] || fail 'Este script no acepta argumentos.'
for tool in git docker lftp python3 tar mktemp date; do
    command -v "$tool" >/dev/null || fail "Falta la herramienta: $tool"
done

assert_clean_tree() {
    [[ -z "$(git -C "$PROJECT_ROOT" status --porcelain --untracked-files=all)" ]] \
        || fail 'El árbol Git debe estar limpio, incluyendo archivos sin seguimiento.'
}

assert_clean_tree
readonly RELEASE_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD)"
# Nunca archivar una configuración privada, aunque alguien la haya agregado a Git.
if [[ -n "$(git -C "$PROJECT_ROOT" ls-files -- .env .env.deploy .env.testing .env.production auth.json)" ]]; then
    fail 'Git contiene configuración privada; retírala del repositorio antes de continuar.'
fi
[[ -f "$DEPLOY_ENV" && ! -L "$DEPLOY_ENV" ]] || fail 'Crea .env.deploy desde .env.deploy.example.'
docker info >/dev/null 2>&1 || fail 'Docker no está disponible.'
for image in "$PHP_IMAGE" "$MYSQL_IMAGE"; do
    docker image inspect "$image" >/dev/null 2>&1 || fail "Falta la imagen local: $image"
done

TEMP_ROOT="$(mktemp -d /tmp/buxdev-ftps.XXXXXXXX)"
readonly TEST_ROOT="$TEMP_ROOT/test"
readonly RELEASE_ROOT="$TEMP_ROOT/release"
mkdir -p "$TEST_ROOT" "$RELEASE_ROOT" "$TEMP_ROOT/lftp-home" "$TEMP_ROOT/composer"
mkdir -p "$DEPLOY_LOG_DIR"
chmod 700 "$DEPLOY_LOG_DIR"

# No usar source/eval: los valores de .env.deploy son datos, nunca código shell.
# Las credenciales solo se escriben en un archivo temporal privado, no en argv.
python3 - "$DEPLOY_ENV" "$TEMP_ROOT" <<'PY'
import os
import re
import stat
import sys
from pathlib import Path

env_path, temp_path = map(Path, sys.argv[1:])

def fail(message):
    print(f"Error: {message}", file=sys.stderr)
    sys.exit(1)

if stat.S_IMODE(env_path.stat().st_mode) & 0o077:
    fail(".env.deploy debe ser privado; ejecuta chmod 600 .env.deploy.")

values = {}
allowed = {"FTP_HOST", "FTP_USER", "FTP_PASSWORD", "FTP_PORT"}
for number, raw in enumerate(env_path.read_text(encoding="utf-8").splitlines(), 1):
    line = raw.strip()
    if not line or line.startswith("#"):
        continue
    key, separator, value = line.partition("=")
    key, value = key.strip(), value.strip()
    if not separator or key not in allowed or key in values:
        fail(f"Formato o variable no permitida en .env.deploy, línea {number}.")
    if value[:1] in ("'", '"'):
        if len(value) < 2 or value[-1] != value[0]:
            fail(f"Comillas sin cerrar en .env.deploy, línea {number}.")
        value = value[1:-1]
    if not value or any(ord(char) < 32 or ord(char) == 127 for char in value):
        fail(f"Valor vacío o caracteres de control en .env.deploy, línea {number}.")
    values[key] = value

if values.keys() != allowed:
    fail(".env.deploy debe definir FTP_HOST, FTP_USER, FTP_PASSWORD y FTP_PORT.")
host = values["FTP_HOST"]
if len(host) > 253 or not all(re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?", label) for label in host.split(".")):
    fail("FTP_HOST debe ser un hostname, sin protocolo, puerto ni ruta.")
port = values["FTP_PORT"]
if not port.isascii() or not port.isdigit() or not 1 <= int(port) <= 65535:
    fail("FTP_PORT debe ser un puerto entre 1 y 65535.")
if "," in values["FTP_USER"]:
    fail("FTP_USER no puede contener comas.")

def quote(value):
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'

settings = '''set cmd:fail-exit yes
set cmd:interactive no
set cmd:save-cwd-history no
set log:enabled no
set log:enabled/xfer no
set ftp:proxy ""
set ftp:ssl-allow yes
set ftp:ssl-force yes
set ftp:ssl-auth TLS
set ftp:ssl-protect-data yes
set ftp:ssl-protect-list yes
set ftp:ssl-use-ccc no
set ssl:verify-certificate yes
set ssl:check-hostname yes
set net:max-retries 1
set net:timeout 30
set xfer:timeout 60
set xfer:use-temp-file yes
set xfer:temp-file-name .buxdev-upload-*.part
'''
# ftp:// + AUTH TLS obligatorio = FTPS explícito, sin fallback a FTP plano.
settings += f'open -u {quote(values["FTP_USER"] + "," + values["FTP_PASSWORD"])} -p {int(port)} ftp://{host}\n'
settings += 'cd /\n'
(temp_path / "connection.lftp").write_text(settings, encoding="utf-8")
os.chmod(temp_path / "connection.lftp", 0o600)
(temp_path / "host").write_text(f"{host}:{int(port)}", encoding="utf-8")
(temp_path / "redact-secrets").write_text(values["FTP_PASSWORD"] + "\n" + values["FTP_USER"] + "\n", encoding="utf-8")
os.chmod(temp_path / "redact-secrets", 0o600)
PY

docker_php() {
    local directory=$1
    shift
    docker run --rm --pull=never --user "$(id -u):$(id -g)" \
        -e COMPOSER_HOME=/tmp/composer \
        -v "$directory:/app:z" -v "$TEMP_ROOT/composer:/tmp/composer:z" "$PHP_IMAGE" "$@"
}

persist_sanitized_transfer_log() {
    local source_log=$1
    [[ -f "$source_log" ]] || return 0

    local timestamp destination
    timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
    destination="$DEPLOY_LOG_DIR/ftps-failure-$timestamp.log"

    python3 - "$source_log" "$TEMP_ROOT/redact-secrets" "$destination" <<'PY'
import os
import sys
from pathlib import Path

source, secrets_path, destination = map(Path, sys.argv[1:])
text = source.read_text(encoding="utf-8", errors="replace")
for secret in secrets_path.read_text(encoding="utf-8").splitlines():
    if secret:
        text = text.replace(secret, "[REDACTED]")

destination.write_text(text, encoding="utf-8")
os.chmod(destination, 0o600)
PY

    printf 'Log FTPS sanitizado guardado en: %s\n' "$destination" >&2
}

printf 'Validando commit %s en una copia temporal...\n' "$RELEASE_COMMIT"
git -C "$PROJECT_ROOT" archive "$RELEASE_COMMIT" | tar -x -C "$TEST_ROOT"
cp "$TEST_ROOT/.env.example" "$TEST_ROOT/.env"
docker_php "$TEST_ROOT" composer install --prefer-dist --no-interaction
docker_php "$TEST_ROOT" php artisan key:generate --no-interaction

# MySQL temporal sin red externa ni puertos publicados; nunca usa la BD local.
DATABASE_CONTAINER="$(docker run -d --rm --pull=never --network none \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=buxdev_api_testing "$MYSQL_IMAGE")"
database_ready=false
for ((attempt = 0; attempt < 90; attempt++)); do
    if docker exec "$DATABASE_CONTAINER" mysql --protocol=TCP -h 127.0.0.1 \
        -e 'SELECT 1' >/dev/null 2>&1; then
        database_ready=true
        break
    fi
    sleep 1
done
[[ "$database_ready" == true ]] || fail 'MySQL temporal no está listo.'
docker run --rm --pull=never --user "$(id -u):$(id -g)" \
    --network "container:$DATABASE_CONTAINER" \
    -e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1 -e DB_PORT=3306 \
    -e DB_DATABASE=buxdev_api_testing -e DB_USERNAME=root -e DB_PASSWORD= \
    -v "$TEST_ROOT:/app:z" "$PHP_IMAGE" php artisan test
docker_php "$TEST_ROOT" ./vendor/bin/pint --test
docker_php "$TEST_ROOT" composer validate
docker rm --force "$DATABASE_CONTAINER" >/dev/null
DATABASE_CONTAINER=''
LFTP_PID=''

printf 'Generando release de producción desde el mismo commit...\n'
git -C "$PROJECT_ROOT" archive "$RELEASE_COMMIT" | tar -x -C "$RELEASE_ROOT"
# Crear el payload mediante lista permitida, nunca a partir del vendor local.
python3 - "$RELEASE_ROOT" <<'PY'
import shutil
import sys
from pathlib import Path

root = Path(sys.argv[1])
allowed = {"app", "bootstrap", "config", "database", "public", "resources", "routes", "artisan", "composer.json", "composer.lock", ".htaccess", "storage"}
for path in root.iterdir():
    if path.name not in allowed:
        if path.is_dir() and not path.is_symlink():
            shutil.rmtree(path)
        else:
            path.unlink()
# Directorios necesarios para los scripts nativos de Composer; no se subirán.
for directory in ["storage/logs", "storage/framework/cache/data", "storage/framework/views", "bootstrap/cache"]:
    (root / directory).mkdir(parents=True, exist_ok=True)
for path in (root / "bootstrap/cache").glob("*.php"):
    path.unlink()
PY
docker_php "$RELEASE_ROOT" composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
docker_php "$RELEASE_ROOT" composer check-platform-reqs --no-dev

# Eliminar también archivos generados por Composer y cualquier ruta protegida.
python3 - "$RELEASE_ROOT" <<'PY'
import os
import shutil
import sys
from pathlib import Path

root = Path(sys.argv[1])
protected = {"storage", ".well-known", "cgi-bin", "php.ini", ".user.ini", ".ftpquota", ".htaccess.hosting-backup", ".git", "tests", ".phpunit.result.cache"}
for directory, folders, files in os.walk(root, topdown=True, followlinks=False):
    for name in list(folders) + files:
        path = Path(directory) / name
        if name in protected or name == ".env" or name.startswith(".env."):
            if path.is_dir() and not path.is_symlink():
                shutil.rmtree(path)
            else:
                path.unlink()
            if name in folders:
                folders.remove(name)
        elif path.is_symlink():
            sys.exit("Error: el release contiene un enlace simbólico; no se subirá.")
for path in (root / "bootstrap/cache").glob("*.php"):
    path.unlink()
PY

assert_clean_tree
[[ "$(git -C "$PROJECT_ROOT" rev-parse HEAD)" == "$RELEASE_COMMIT" ]] \
    || fail 'HEAD cambió durante la preparación; vuelve a iniciar el proceso.'
printf '\nOrigen local: %s\nCommit: %s\nRelease temporal: %s\n' "$PROJECT_ROOT" "$RELEASE_COMMIT" "$RELEASE_ROOT"
printf 'Host FTPS: %s\nDestino remoto: %s (/home/buxdevco/api.buxdev.com)\n' "$(cat "$TEMP_ROOT/host")" "$REMOTE_ROOT"
printf 'Archivos principales: app/, bootstrap/, config/, database/, public/, resources/, routes/, vendor/, artisan, composer.json, composer.lock, .htaccess y public/.htaccess.\n'
printf 'Se eliminan obsoletos solo dentro de directorios gestionados por Laravel; .env, storage/ y archivos del hosting se preservan.\n'
printf 'bootstrap/cache se sincroniza sin caches PHP locales para invalidar manifests antiguos antes de regenerarlos en producción.\n'
printf 'FTPS actualiza archivos individualmente; no es un cambio atómico del sitio completo.\n'
printf 'Escribe y para confirmar el despliegue: '
confirmation=''
IFS= read -r confirmation || true
[[ "$confirmation" == 'y' ]] || fail 'Despliegue cancelado; no se realizó ninguna conexión FTPS.'
assert_clean_tree
[[ "$(git -C "$PROJECT_ROOT" rev-parse HEAD)" == "$RELEASE_COMMIT" ]] || fail 'HEAD cambió antes de la transferencia.'

# Sincronización determinista: --delete solo dentro de directorios gestionados
# por Laravel. Nunca se borra de forma recursiva la raíz remota.
cat >> "$TEMP_ROOT/connection.lftp" <<EOF
put "$RELEASE_ROOT/artisan" -o /artisan
put "$RELEASE_ROOT/composer.json" -o /composer.json
put "$RELEASE_ROOT/composer.lock" -o /composer.lock
put "$RELEASE_ROOT/.htaccess" -o /.htaccess

# Limpieza exacta de residuos de releases manuales anteriores. Nunca usar .env*.
rm -f /.editorconfig /.env.example /.env.production.example /.env.deploy.example /.gitattributes /.gitignore /phpunit.xml /README.md /buxdev-api-release.zip

mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/app" /app
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 --exclude '^cache(/|$)' "$RELEASE_ROOT/bootstrap" /bootstrap
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/bootstrap/cache" /bootstrap/cache
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/config" /config
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/database" /database
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 --exclude '^\.well-known(/|$)' "$RELEASE_ROOT/public" /public
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/resources" /resources
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/routes" /routes
mirror --reverse --delete --transfer-all --no-perms --no-symlinks --parallel=3 "$RELEASE_ROOT/vendor" /vendor
bye
EOF
printf 'Iniciando transferencia FTPS con certificado TLS verificado...\n'
# lftp queda en segundo plano para poder mostrar un heartbeat seguro sin imprimir
# respuestas crudas del servidor ni credenciales.
LFTP_HOME="$TEMP_ROOT/lftp-home" lftp --norc -f "$TEMP_ROOT/connection.lftp" \
    >"$TEMP_ROOT/transfer.log" 2>&1 &
LFTP_PID=$!
transfer_started=$SECONDS
while kill -0 "$LFTP_PID" >/dev/null 2>&1; do
    sleep 10
    if kill -0 "$LFTP_PID" >/dev/null 2>&1; then
        transfer_size=$(wc -c < "$TEMP_ROOT/transfer.log" 2>/dev/null || printf '0')
        printf 'FTPS en curso... %ss (log local: %s bytes)\n' "$((SECONDS - transfer_started))" "$transfer_size"
    fi
done
if ! wait "$LFTP_PID"; then
    LFTP_PID=''
    persist_sanitized_transfer_log "$TEMP_ROOT/transfer.log"
    fail 'Falló la transferencia FTPS (TLS, conexión o escritura). Puede haber archivos actualizados; no se confirma un deploy completo. Revisa el log FTPS sanitizado y reintenta el release completo.'
fi
LFTP_PID=''
printf 'Transferencia FTPS completada en %ss.\n' "$((SECONDS - transfer_started))"

cat <<'POST_DEPLOY'

Transferencia completada. bootstrap/cache quedó sin caches PHP locales para evitar
arrastrar manifests/configuración del entorno de desarrollo. Configura temporalmente
estos comandos en Cron cPanel, en este orden; comprueba cada resultado antes del
siguiente y elimina los Cron al finalizar. No se ha ejecutado Artisan remotamente.

/usr/local/bin/php /home/buxdevco/api.buxdev.com/artisan package:discover --ansi
/usr/local/bin/php /home/buxdevco/api.buxdev.com/artisan migrate --force --no-interaction
/usr/local/bin/php /home/buxdevco/api.buxdev.com/artisan config:cache
/usr/local/bin/php /home/buxdevco/api.buxdev.com/artisan route:cache
/usr/local/bin/php /home/buxdevco/api.buxdev.com/artisan view:cache

Smoke tests después de los Cron:
curl -i https://api.buxdev.com/health
curl -i 'https://api.buxdev.com/v1/build/articles?locale=es'
curl -i 'https://api.buxdev.com/v1/build/articles?locale=invalid'

El último smoke test debe responder HTTP 400 con:
{"message":"Invalid locale.","errors":{"locale":["Supported locales are: es, en."]}}
POST_DEPLOY