#!/usr/bin/env bash
set -euo pipefail

# Solo se gestionan los servicios del proyecto Compose indicado; nunca se descartan volúmenes.
if [ "$#" -lt 4 ] || [ "$#" -gt 5 ]; then
    echo 'Uso: bash scripts/Deploy-Compose.sh PROYECTO ARCHIVO_ENV VERSION REVISION [--initial]' >&2
    exit 2
fi
project="$1"
env_file="$2"
export RELEASE_VERSION="$3" RELEASE_COMMIT="$4"
initial="${5:-}"
[[ "$project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
[[ "$RELEASE_VERSION" =~ ^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$ ]] || exit 2
[[ "$RELEASE_COMMIT" =~ ^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$ ]] || exit 2
[[ -z "$initial" || "$initial" == '--initial' ]] || exit 2
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
[[ -f "$env_file" ]] || { echo 'No existe el archivo de entorno.' >&2; exit 2; }
export APP_ENV_FILE="$(realpath "$env_file")"
export RELEASE_TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
export RELEASE_IMAGE="calmina-aroma:${RELEASE_VERSION}-${RELEASE_COMMIT:0:24}"
compose=(docker compose --project-name "$project" --env-file "$env_file" -f docker-compose.local.yml -f docker-compose.release.yml)
stage='validación de configuración'
previous='sin instalación previa'
release_touched=false
release_failure() {
    trap - ERR
    if [[ "$release_touched" == 'true' ]]; then
        "${compose[@]}" exec -T app-ferreteria php artisan down --retry=60 >/dev/null 2>&1 || true
    fi
    echo "Despliegue incompleto. Etapa: $stage. Imagen previa: $previous. Revisa respaldo y mantenimiento; no se afirma reversión de migraciones." >&2
}
trap release_failure ERR
docker info >/dev/null
"${compose[@]}" config --quiet
current="$("${compose[@]}" ps --all -q app-ferreteria)"
if [[ -n "$current" ]]; then
    [[ -z "$initial" ]] || { echo 'No se permite --initial sobre una instalación existente.' >&2; exit 2; }
    previous="$(docker inspect --format '{{.Image}}' "$current")"
else
    [[ "$initial" == '--initial' ]] || { echo 'La primera instalación requiere --initial.' >&2; exit 2; }
    if docker volume inspect "${project}_mysql_data" >/dev/null 2>&1; then
        echo 'Existe un volumen de base previo. Revisa su respaldo y recuperación antes de iniciar una instalación.' >&2
        exit 2
    fi
fi
stage='construcción de imagen'
"${compose[@]}" build app-ferreteria
image_id="$(docker image inspect --format '{{.Id}}' "$RELEASE_IMAGE")"
if [[ -n "$current" ]]; then
    release_touched=true
    stage='detención de escritores y respaldo verificado'
    "${compose[@]}" stop queue-ferreteria scheduler-ferreteria maintenance-ferreteria
    "${compose[@]}" exec -T app-ferreteria php artisan down --retry=60
    "${compose[@]}" exec -T app-ferreteria php artisan production:pre-update --target="$RELEASE_VERSION"
else
    stage='arranque inicial de base'
    "${compose[@]}" up -d --wait --wait-timeout 180 db-ferreteria
fi
stage='sustitución de aplicación y migraciones'
release_touched=true
"${compose[@]}" up -d --no-deps --no-build --force-recreate --wait --wait-timeout 1800 app-ferreteria
"${compose[@]}" exec -T app-ferreteria php artisan up
stage='verificación de publicación'
"${compose[@]}" exec -T app-ferreteria php artisan deployment:verify --expected-commit="$RELEASE_COMMIT"
active="$("${compose[@]}" ps -q app-ferreteria)"
[[ "$(docker inspect --format '{{.Image}}' "$active")" == "$image_id" ]]
stage='reinicio de procesos'
"${compose[@]}" up -d --no-deps --no-build --force-recreate --wait --wait-timeout 120 queue-ferreteria scheduler-ferreteria maintenance-ferreteria
mkdir -p storage/app/private/deployments
umask 077
printf '%s\n' "version=$RELEASE_VERSION" "revision=$RELEASE_COMMIT" "image=$image_id" "previous=$previous" "completed_at=$RELEASE_TIMESTAMP" > "storage/app/private/deployments/${project}-${RELEASE_TIMESTAMP//:/-}.txt"
trap - ERR
echo "Despliegue completado y verificado: $RELEASE_VERSION ($image_id)."
