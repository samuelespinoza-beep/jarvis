# Comandos de desarrollo del Asistente IA (Linux). En la laptop Windows se usa Iniciar-Jarvis.bat.
# El puerto se toma de SERVER_PORT en el .env (8074; el 8073 queda para la versión instalada).

# Procesos que crea "make dev" (servidor, cola, logs y Vite). "make down" solo detiene
# los que corren dentro de esta carpeta, así no toca otros proyectos Laravel.
PROCESOS = composer run dev|artisan (serve|dev|queue:listen|queue:work|pail)|resources/server\.php|bin/multiplex|bin/vp dev|vite-plus-core/dist/vite/node/cli\.js

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-12s\033[0m %s\n", $$1, $$2}'

dev: ## Levanta servidor (8074), cola de trabajos, logs y Vite. Ctrl+C para detener
	@composer run dev

down: ## Detiene los procesos de este proyecto que quedaron corriendo (no toca otros proyectos)
	@detenidos=0; \
	for pid in $$(pgrep -f '$(PROCESOS)'); do \
		[ $$pid = $$$$ ] && continue; \
		case "$$(readlink /proc/$$pid/cwd 2>/dev/null)" in \
			$(CURDIR)|$(CURDIR)/*) kill $$pid 2>/dev/null && detenidos=$$((detenidos + 1));; \
		esac; \
	done; \
	[ $$detenidos -gt 0 ] && echo "Procesos detenidos: $$detenidos" || echo "No hay procesos de Jarvis corriendo"

clear: ## Limpia cachés de Laravel (config, rutas, vistas, eventos y caché de la app)
	@php artisan optimize:clear

install: ## Instala dependencias (composer y npm) y ejecuta las migraciones
	@composer install
	@npm install
	@php artisan migrate

migrate: ## Ejecuta las migraciones pendientes
	@php artisan migrate

fresh: ## BORRA la base de datos, la recrea y crea el usuario de desarrollo (pide confirmación)
	@read -p "Se borrarán TODAS las reuniones de la base de datos. ¿Continuar? [s/N] " r; \
	[ "$$r" = "s" ] && php artisan migrate:fresh --seed || echo "Cancelado"

test: ## Ejecuta los tests (no llaman a OpenAI)
	@php artisan test

lint: ## Revisa y corrige el formato de PHP (Pint) y el frontend (lint + formato)
	@vendor/bin/pint
	@npx vp check --fix

build: ## Compila el frontend para producción (public/build)
	@npm run build

instalador: ## Genera los instaladores de Windows, Linux y Mac en dist/ (necesita Docker)
	@instalador/preparar-app.sh
	@instalador/linux/empaquetar.sh
	@instalador/windows/empaquetar.sh
	@instalador/mac/empaquetar.sh

instalador-linux: ## Genera solo el instalador de Linux (dist/Asistente-IA-*-linux.run)
	@instalador/preparar-app.sh
	@instalador/linux/empaquetar.sh

instalador-windows: ## Genera solo el instalador de Windows (dist/Asistente-IA-*-windows.exe, necesita Docker)
	@instalador/preparar-app.sh
	@instalador/windows/empaquetar.sh

instalador-mac: ## Genera solo la app de Mac (dist/Asistente-IA-*-mac.zip)
	@instalador/preparar-app.sh
	@instalador/mac/empaquetar.sh

.PHONY: help dev down clear install migrate fresh test lint build instalador instalador-linux instalador-windows instalador-mac
