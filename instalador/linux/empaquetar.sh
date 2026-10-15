#!/usr/bin/env bash
# Genera dist/Asistente-IA-<versión>-linux.run: un solo archivo que se ejecuta y
# deja el Asistente IA instalado (trae su propio PHP, no necesita instalar nada más).
# Requiere que dist/app exista (instalador/preparar-app.sh).
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
DESCARGAS="$RAIZ/instalador/.descargas"
PAQUETE="$RAIZ/dist/linux/jarvis"
VERSION="${VERSION:-$(date +%Y.%m.%d)}"
PHP_SERIE="8.4"

mkdir -p "$DESCARGAS"

# PHP estático (static-php-cli): un binario con SQLite, curl, mbstring, etc.
URL_PHP="https://dl.static-php.dev/static-php-cli/common"
ARCHIVO_PHP="$(curl -fsSL "$URL_PHP/" | grep -o "php-$PHP_SERIE\.[0-9]*-cli-linux-x86_64\.tar\.gz" | sort -uV | tail -1)"
if [ ! -f "$DESCARGAS/$ARCHIVO_PHP" ]; then
    echo "==> Descargando $ARCHIVO_PHP"
    curl -fL --progress-bar -o "$DESCARGAS/$ARCHIVO_PHP" "$URL_PHP/$ARCHIVO_PHP"
fi

if [ ! -f "$DESCARGAS/cacert.pem" ]; then
    echo "==> Descargando certificados raíz (cacert.pem)"
    curl -fsSL -o "$DESCARGAS/cacert.pem" https://curl.se/ca/cacert.pem
fi

echo "==> Armando el paquete de Linux"
rm -rf "$RAIZ/dist/linux"
mkdir -p "$PAQUETE/php"
cp -a "$RAIZ/dist/app" "$PAQUETE/app"
tar -xzf "$DESCARGAS/$ARCHIVO_PHP" -C "$PAQUETE/php"
chmod +x "$PAQUETE/php/php"
cp "$DESCARGAS/cacert.pem" "$PAQUETE/php/"
cp "$RAIZ"/instalador/linux/{php.ini,jarvis-servidor.service,jarvis-cola.service,jarvis.desktop,jarvis,instalar.sh,desinstalar.sh} "$PAQUETE/"

# El PHP descargado debe tener lo que la app necesita.
faltan="$("$PAQUETE/php/php" -m | tr 'A-Z' 'a-z' | sort > /tmp/jarvis-ext.$$; \
    for ext in curl fileinfo mbstring openssl pdo_sqlite sqlite3 tokenizer dom xml ctype; do \
        grep -qx "$ext" /tmp/jarvis-ext.$$ || printf '%s ' "$ext"; done; rm -f /tmp/jarvis-ext.$$)"
if [ -n "$faltan" ]; then
    echo "Al PHP descargado le faltan extensiones: $faltan" >&2
    exit 1
fi

SALIDA="$RAIZ/dist/Asistente-IA-$VERSION-linux.run"
{
    cat <<'CABECERA'
#!/bin/sh
# Instalador del Asistente IA para Linux. Uso: sh Asistente-IA-linux.run
set -e
TEMPORAL="$(mktemp -d)"
trap 'rm -rf "$TEMPORAL"' EXIT
LINEA=$(awk '/^__PAQUETE__$/ { print NR + 1; exit 0 }' "$0")
tail -n +"$LINEA" "$0" | tar -xz -C "$TEMPORAL"
"$TEMPORAL/jarvis/instalar.sh" "$@"
exit 0
__PAQUETE__
CABECERA
    tar -czf - -C "$RAIZ/dist/linux" jarvis
} > "$SALIDA"
chmod +x "$SALIDA"

echo "==> Listo: $SALIDA ($(du -h "$SALIDA" | cut -f1))"
