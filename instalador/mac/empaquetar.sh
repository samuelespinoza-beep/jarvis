#!/usr/bin/env bash
# Genera dist/Asistente-IA-<versión>-mac.zip con Asistente IA.app (Intel y Apple Silicon).
# Al abrir la app por primera vez se configura sola; no hay instalador aparte.
# Requiere que dist/app exista (instalador/preparar-app.sh) e ImageMagick (convert).
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
DESCARGAS="$RAIZ/instalador/.descargas"
CARPETA="$RAIZ/dist/mac/Asistente IA"
APP="$CARPETA/Asistente IA.app"
VERSION="${VERSION:-$(date +%Y.%m.%d)}"
PHP_SERIE="8.4"

mkdir -p "$DESCARGAS"

# PHP estático (static-php-cli) para Intel y para Apple Silicon.
URL_PHP="https://dl.static-php.dev/static-php-cli/common"
LISTADO="$(curl -fsSL "$URL_PHP/")"
for arquitectura in x86_64 aarch64; do
    archivo="$(echo "$LISTADO" | grep -o "php-$PHP_SERIE\.[0-9]*-cli-macos-$arquitectura\.tar\.gz" | sort -uV | tail -1)"
    if [ ! -f "$DESCARGAS/$archivo" ]; then
        echo "==> Descargando $archivo"
        curl -fL --progress-bar -o "$DESCARGAS/$archivo" "$URL_PHP/$archivo"
    fi
    eval "PHP_$arquitectura=\"\$archivo\""
done

if [ ! -f "$DESCARGAS/cacert.pem" ]; then
    echo "==> Descargando certificados raíz (cacert.pem)"
    curl -fsSL -o "$DESCARGAS/cacert.pem" https://curl.se/ca/cacert.pem
fi

echo "==> Armando Asistente IA.app"
rm -rf "$RAIZ/dist/mac"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources/php"
RECURSOS="$APP/Contents/Resources"

sed "s|@VERSION@|$VERSION|g" "$RAIZ/instalador/mac/Info.plist" > "$APP/Contents/Info.plist"
install -m 755 "$RAIZ/instalador/mac/Jarvis" "$APP/Contents/MacOS/Jarvis"
cp -a "$RAIZ/dist/app" "$RECURSOS/app"
cp "$RAIZ"/instalador/mac/{php.ini,local.jarvis.servidor.plist,local.jarvis.cola.plist} "$RECURSOS/"
install -m 755 "$RAIZ/instalador/mac/Desinstalar Asistente IA.command" "$RECURSOS/"
echo "$VERSION" > "$RECURSOS/VERSION"
cp "$DESCARGAS/cacert.pem" "$RECURSOS/php/"

TEMPORAL="$(mktemp -d)"
trap 'rm -rf "$TEMPORAL"' EXIT
tar -xzf "$DESCARGAS/$PHP_x86_64" -C "$TEMPORAL" && mv "$TEMPORAL/php" "$RECURSOS/php/php-x86_64"
tar -xzf "$DESCARGAS/$PHP_aarch64" -C "$TEMPORAL" && mv "$TEMPORAL/php" "$RECURSOS/php/php-arm64"
chmod 755 "$RECURSOS"/php/php-*

# Ícono de macOS (.icns) con las versiones de 256 y 512 px del ícono de la app.
convert -background none -resize 256x256 "$RAIZ/public/apple-touch-icon.png" "$TEMPORAL/256.png"
convert -background none -resize 512x512 "$RAIZ/public/apple-touch-icon.png" "$TEMPORAL/512.png"
python3 - "$TEMPORAL/256.png" "$TEMPORAL/512.png" "$RECURSOS/AppIcon.icns" <<'PY'
import struct, sys
entradas = b''.join(
    tipo + struct.pack('>I', 8 + len(datos)) + datos
    for tipo, datos in ((b'ic08', open(sys.argv[1], 'rb').read()), (b'ic09', open(sys.argv[2], 'rb').read()))
)
open(sys.argv[3], 'wb').write(b'icns' + struct.pack('>I', 8 + len(entradas)) + entradas)
PY

install -m 755 "$RAIZ/instalador/mac/Desinstalar Asistente IA.command" "$CARPETA/"
cat > "$CARPETA/LEEME.txt" <<'LEEME'
ASISTENTE IA PARA MAC

1. Arrastra Asistente IA.app a la carpeta Aplicaciones.
2. La primera vez: clic derecho sobre Asistente IA.app > Abrir > Abrir.
   (macOS avisa porque la app no está firmada por Apple.)
3. Pega tu llave de OpenAI cuando la pida.
4. Se abre el navegador en http://127.0.0.1:8073 (usa Chrome o Safari).
   Permite el uso del micrófono.

Las siguientes veces, ábrelo con doble clic como cualquier app.
Tus reuniones quedan en ~/Library/Application Support/Jarvis
Para desinstalar: doble clic en "Desinstalar Asistente IA.command".
Si tenías la versión anterior (Jarvis.app), llévala a la Papelera: tus reuniones se conservan.
LEEME

SALIDA="$RAIZ/dist/Asistente-IA-$VERSION-mac.zip"
rm -f "$SALIDA"
# zip conserva los permisos de ejecución, que macOS respeta al descomprimir.
(cd "$RAIZ/dist/mac" && zip -qry "$SALIDA" "Asistente IA")

echo "==> Listo: $SALIDA ($(du -h "$SALIDA" | cut -f1))"
