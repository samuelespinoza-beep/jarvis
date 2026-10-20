#!/bin/bash
# Desinstala el Asistente IA de esta Mac. Las reuniones se respaldan salvo que se pida borrarlas.
DESTINO="$HOME/Library/Application Support/Jarvis"
AGENTES="$HOME/Library/LaunchAgents"

for agente in servidor cola; do
    launchctl bootout "gui/$(id -u)/local.jarvis.$agente" 2>/dev/null
    rm -f "$AGENTES/local.jarvis.$agente.plist"
done

read -r -p "¿Borrar también las reuniones y la configuración? [s/N] " respuesta
if [ "$respuesta" != "s" ] && [ -d "$DESTINO/app" ]; then
    RESPALDO="$HOME/Asistente-IA-respaldo-$(date +%Y%m%d-%H%M)"
    mkdir -p "$RESPALDO/database" "$RESPALDO/storage"
    cp -a "$DESTINO/app/.env" "$RESPALDO/" 2>/dev/null
    cp -a "$DESTINO/app/database/database.sqlite" "$RESPALDO/database/" 2>/dev/null
    cp -a "$DESTINO/app/storage/app" "$RESPALDO/storage/" 2>/dev/null
    echo "Reuniones y configuración respaldadas en $RESPALDO"
fi

rm -rf "$DESTINO"
echo
echo "El Asistente IA quedó desinstalado. Arrastra Asistente IA.app de Aplicaciones a la Papelera."
