#!/usr/bin/env bash
# Instala o actualiza el Asistente IA en la carpeta del usuario (no necesita sudo).
# Al actualizar conserva la configuración (.env), la base de datos y los audios.
set -euo pipefail

ORIGEN="$(cd "$(dirname "$0")" && pwd)"
DESTINO="${XDG_DATA_HOME:-$HOME/.local/share}/jarvis"
SERVICIOS="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
ACCESOS="${XDG_DATA_HOME:-$HOME/.local/share}/applications"
LLAVE="${JARVIS_OPENAI_KEY:-}"

echo "Instalando el Asistente IA en $DESTINO"
systemctl --user stop jarvis-servidor jarvis-cola 2>/dev/null || true
mkdir -p "$DESTINO" "$SERVICIOS" "$ACCESOS"

# Programa nuevo, rescatando los datos de la instalación anterior.
rm -rf "$DESTINO/app.nueva"
cp -a "$ORIGEN/app" "$DESTINO/app.nueva"
if [ -d "$DESTINO/app" ]; then
    for dato in .env database/database.sqlite storage/app storage/logs; do
        if [ -e "$DESTINO/app/$dato" ]; then
            rm -rf "$DESTINO/app.nueva/$dato"
            mv "$DESTINO/app/$dato" "$DESTINO/app.nueva/$dato"
        fi
    done
    rm -rf "$DESTINO/app"
fi
mv "$DESTINO/app.nueva" "$DESTINO/app"

mkdir -p "$DESTINO/php"
install -m 755 "$ORIGEN/php/php" "$DESTINO/php/php"
install -m 644 "$ORIGEN/php/cacert.pem" "$DESTINO/php/cacert.pem"
sed "s|@DESTINO@|$DESTINO|g" "$ORIGEN/php.ini" > "$DESTINO/php/php.ini"
install -m 755 "$ORIGEN/jarvis" "$DESTINO/jarvis"
install -m 755 "$ORIGEN/desinstalar.sh" "$DESTINO/desinstalar.sh"

# La llave de OpenAI se pide solo si todavía no hay una.
if [ -z "$LLAVE" ] && ! grep -qE '^OPENAI_API_KEY=.+' "$DESTINO/app/.env" 2>/dev/null; then
    if [ -t 0 ]; then
        read -r -s -p "Pega tu llave de OpenAI (empieza con sk-) y pulsa Enter: " LLAVE
        echo
    else
        echo "Aviso: falta la llave de OpenAI. Vuelve a ejecutar el instalador con JARVIS_OPENAI_KEY=sk-..."
    fi
fi

echo "Configurando la base de datos…"
(cd "$DESTINO/app" && PHPRC="$DESTINO/php" "$DESTINO/php/php" artisan jarvis:instalar --llave="$LLAVE" --no-interaction)

for unidad in jarvis-servidor.service jarvis-cola.service; do
    sed "s|@DESTINO@|$DESTINO|g" "$ORIGEN/$unidad" > "$SERVICIOS/$unidad"
done
sed "s|@DESTINO@|$DESTINO|g" "$ORIGEN/jarvis.desktop" > "$ACCESOS/jarvis.desktop"

systemctl --user daemon-reload
# Quedan corriendo en segundo plano y arrancan solos al iniciar sesión.
systemctl --user enable --now jarvis-servidor jarvis-cola >/dev/null

echo
echo "El Asistente IA quedó instalado. Ábrelo desde el menú de aplicaciones o en http://127.0.0.1:8073"
echo "Para desinstalarlo: $DESTINO/desinstalar.sh"
