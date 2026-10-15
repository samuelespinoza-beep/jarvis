#!/usr/bin/env bash
# Prepara en dist/app una copia del Asistente IA lista para producción: solo los
# archivos del proyecto (sin los ignorados por git), dependencias de PHP sin
# las de desarrollo y el frontend ya compilado. La usan los dos instaladores.
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
APP="$RAIZ/dist/app"

echo "==> Copiando el proyecto a dist/app"
rm -rf "$APP"
mkdir -p "$APP"
cd "$RAIZ"
# Archivos versionados y nuevos (aún sin commit), sin lo que ignora git.
git ls-files -z --cached --others --exclude-standard \
    | tar --null -T - -cf - | tar -xf - -C "$APP"

cd "$APP"

echo "==> Dependencias de PHP (sin las de desarrollo)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

echo "==> Compilando el frontend"
if [ -s "$HOME/.nvm/nvm.sh" ]; then
    # shellcheck disable=SC1091
    source "$HOME/.nvm/nvm.sh"
    nvm use 22 >/dev/null
fi
npm ci --no-audit --no-fund --loglevel=error
VITE_APP_NAME="$(grep '^APP_NAME=' "$RAIZ/instalador/env.escritorio" | cut -d= -f2- | tr -d '"')" \
    npm run build >/dev/null

echo "==> Quitando lo que no se usa en producción"
rm -rf node_modules tests .github instalador resources/js resources/css \
    phpunit.xml phpstan.neon pint.json components.json .editorconfig \
    .npmrc pnpm-workspace.yaml tsconfig.json vite.config.ts package.json \
    package-lock.json Makefile .env.example .gitattributes .gitignore
rm -f database/database.sqlite public/hot

cp "$RAIZ/instalador/env.escritorio" "$APP/.env.escritorio"

echo "==> App lista en dist/app ($(du -sh "$APP" | cut -f1))"
