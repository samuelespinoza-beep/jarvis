#!/usr/bin/env bash
# Desinstala el Asistente IA. Las reuniones se respaldan salvo que se pida borrarlas.
set -euo pipefail

DESTINO="${XDG_DATA_HOME:-$HOME/.local/share}/jarvis"
SERVICIOS="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"

systemctl --user disable --now jarvis-servidor jarvis-cola 2>/dev/null || true
rm -f "$SERVICIOS/jarvis-servidor.service" "$SERVICIOS/jarvis-cola.service"
rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/applications/jarvis.desktop"
systemctl --user daemon-reload

respuesta="n"
if [ -t 0 ]; then
    read -r -p "¿Borrar también las reuniones y la configuración? [s/N] " respuesta
fi

if [ "$respuesta" != "s" ] && [ -d "$DESTINO/app" ]; then
    RESPALDO="$HOME/Asistente-IA-respaldo-$(date +%Y%m%d-%H%M)"
    mkdir -p "$RESPALDO/database" "$RESPALDO/storage"
    cp -a "$DESTINO/app/.env" "$RESPALDO/" 2>/dev/null || true
    cp -a "$DESTINO/app/database/database.sqlite" "$RESPALDO/database/" 2>/dev/null || true
    cp -a "$DESTINO/app/storage/app" "$RESPALDO/storage/" 2>/dev/null || true
    echo "Reuniones y configuración respaldadas en $RESPALDO"
fi

rm -rf "$DESTINO"
echo "El Asistente IA quedó desinstalado."
