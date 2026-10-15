#!/usr/bin/env bash
# Genera dist/Asistente-IA-<versión>-windows.exe desde Linux: arma la carpeta con
# PHP para Windows y compila el instalador con Inno Setup dentro de Docker
# (imagen amake/innosetup, que trae Inno Setup sobre Wine).
# Requiere que dist/app exista (instalador/preparar-app.sh).
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
DESCARGAS="$RAIZ/instalador/.descargas"
CARPETA="$RAIZ/dist/windows"
VERSION="${VERSION:-$(date +%Y.%m.%d)}"
PHP_SERIE="8.4"

mkdir -p "$DESCARGAS"

# Última versión de PHP 8.4 para Windows (NTS, x64), verificada con su SHA-256.
read -r ARCHIVO_PHP SHA_PHP < <(curl -fsSL https://downloads.php.net/~windows/releases/releases.json \
    | php -r '$r = json_decode(stream_get_contents(STDIN), true)[$argv[1]]["nts-vs17-x64"]["zip"]; echo $r["path"], " ", $r["sha256"], PHP_EOL;' "$PHP_SERIE")
if [ ! -f "$DESCARGAS/$ARCHIVO_PHP" ]; then
    echo "==> Descargando $ARCHIVO_PHP"
    curl -fL --progress-bar -o "$DESCARGAS/$ARCHIVO_PHP" "https://downloads.php.net/~windows/releases/$ARCHIVO_PHP"
fi
echo "$SHA_PHP  $DESCARGAS/$ARCHIVO_PHP" | sha256sum -c --quiet

if [ ! -f "$DESCARGAS/vc_redist.x64.exe" ]; then
    echo "==> Descargando el runtime de Visual C++"
    curl -fL --progress-bar -o "$DESCARGAS/vc_redist.x64.exe" https://aka.ms/vs/17/release/vc_redist.x64.exe
fi

if [ ! -f "$DESCARGAS/cacert.pem" ]; then
    echo "==> Descargando certificados raíz (cacert.pem)"
    curl -fsSL -o "$DESCARGAS/cacert.pem" https://curl.se/ca/cacert.pem
fi

echo "==> Armando la carpeta de Windows"
rm -rf "$CARPETA"
mkdir -p "$CARPETA/php"
cp -a "$RAIZ/dist/app" "$CARPETA/app"
unzip -q "$DESCARGAS/$ARCHIVO_PHP" -d "$CARPETA/php"
# Nombre propio: "Detener Asistente IA" solo cierra estos procesos, no otros PHP de la PC.
cp "$CARPETA/php/php.exe" "$CARPETA/php/jarvis-php.exe"
cp "$DESCARGAS/cacert.pem" "$CARPETA/php/"
cp "$DESCARGAS/vc_redist.x64.exe" "$CARPETA/"

# Windows espera saltos de línea CRLF; el .iss además en UTF-8 con BOM por las tildes.
sed 's/$/\r/' "$RAIZ/instalador/windows/php.ini" > "$CARPETA/php/php.ini"
for vbs in Jarvis.vbs Detener-Jarvis.vbs; do
    sed 's/$/\r/' "$RAIZ/instalador/windows/$vbs" > "$CARPETA/$vbs"
done
{ printf '\xEF\xBB\xBF'; sed 's/$/\r/' "$RAIZ/instalador/windows/jarvis.iss"; } > "$CARPETA/jarvis.iss"

# Windows limita las rutas a 260 caracteres; se avisa si alguna se acerca.
LARGA="$(cd "$CARPETA/app" && find . -type f | awk '{ print length($0) }' | sort -n | tail -1)"
if [ "$LARGA" -gt 150 ]; then
    echo "Aviso: la ruta más larga dentro de app mide $LARGA caracteres." >&2
fi

echo "==> Compilando el instalador con Inno Setup (Docker)"
# La carpeta de salida se crea antes para que quede a nombre del usuario, no de Docker.
mkdir -p "$CARPETA/salida"
docker run --rm -v "$CARPETA:/work" -w /work amake/innosetup "/DVersion=$VERSION" jarvis.iss >/dev/null

SALIDA="$RAIZ/dist/Asistente-IA-$VERSION-windows.exe"
mv "$CARPETA/salida/Asistente-IA-$VERSION-windows.exe" "$SALIDA"
echo "==> Listo: $SALIDA ($(du -h "$SALIDA" | cut -f1))"
