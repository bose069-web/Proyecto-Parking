#!/usr/bin/env bash
# Pruebas de aceptación del Sprint 2 contra el stack ya levantado.
# Requiere Docker y el proyecto en marcha: docker compose up -d
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
    echo "Falta .env" >&2
    exit 1
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

# Los puertos salen de .env: si se cambian, la prueba sigue apuntando bien.
HTTP_PORT="${HTTP_PORT:-80}"
HTTPS_PORT="${HTTPS_PORT:-443}"
PMA_PORT="${PMA_PORT:-8080}"
APP="https://localhost:${HTTPS_PORT}"
SITE="http://localhost:${HTTP_PORT}"

fallos=0
total=0

ok()   { total=$((total+1)); echo "  OK    $1"; }
fallo(){ total=$((total+1)); fallos=$((fallos+1)); echo "  FALLO $1"; }

http_code() {
    curl -k -s -o /dev/null -w "%{http_code}" --max-time 10 "$1"
}

echo "1. Contenedores"
if docker compose ps --status running --format '{{.Name}}' | grep -q tarraco-app; then
    ok "el contenedor de la aplicación está en marcha"
else
    fallo "el contenedor de la aplicación no está en marcha"
fi
if docker compose ps --status running --format '{{.Name}}' | grep -q tarraco-db; then
    ok "el contenedor de la base de datos está en marcha"
else
    fallo "el contenedor de la base de datos no está en marcha"
fi

printf "\n2. HTTPS y redirección"
code=$(http_code "${APP}/")
[[ "$code" == "200" ]] && ok "${APP}/ responde 200" || fallo "${APP}/ responde $code"

code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${SITE}/")
[[ "$code" == "301" || "$code" == "302" ]] && ok "HTTP redirige a HTTPS ($code)" || fallo "HTTP responde $code, no redirige"

for ruta in /login.php /register.php /admin/login.php; do
    code=$(http_code "${APP}${ruta}")
    [[ "$code" == "200" ]] && ok "${ruta} responde 200" || fallo "${ruta} responde $code"
done

# El control de acceso exige sesión: sin ella redirige al login (303).
code=$(http_code "${APP}/access-control.php")
[[ "$code" == "303" || "$code" == "200" ]] \
    && ok "/access-control.php pide sesión ($code)" || fallo "/access-control.php responde $code"

printf "\n3. Base de datos"
q() {
    docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" -N -B "$DB_NAME" -e "$1" 2>/dev/null
}

tablas=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE';")
[[ "$tablas" == "11" ]] && ok "hay 11 tablas" || fallo "hay $tablas tablas, se esperaban 11"

# Las 20 del seed son el mínimo. Usar la aplicación crea más, y eso no es un
# fallo: la prueba solo comprueba que los datos de ejemplo están cargados.
reservas=$(q "SELECT COUNT(*) FROM RESERVA;")
[[ "$reservas" -ge 20 ]] && ok "hay al menos 20 reservas de prueba ($reservas)" || fallo "hay $reservas reservas, se esperaban 20 o más"

malas=$(q "SELECT COUNT(*) FROM RESERVA r JOIN TARIFA t ON t.id_tarifa=r.id_tarifa
 WHERE ABS(r.importe_base - ROUND(GREATEST(1, CEIL(TIMESTAMPDIFF(MINUTE, r.fecha_hora_entrada_prevista, r.fecha_hora_salida_prevista)/60)) * t.precio_hora,2)) > 0.01
    OR ABS(r.importe_gestion - ROUND(r.importe_base * t.porcentaje_gestion,2)) > 0.01
    OR ABS(r.importe_total - ROUND(r.importe_base + r.importe_gestion,2)) > 0.01;")
[[ "$malas" == "0" ]] && ok "los importes de todas las reservas cuadran con la tarifa" || fallo "$malas reservas con importes incoherentes"

excesos=$(q "SELECT COUNT(*) FROM RESERVA
 WHERE fecha_hora_salida_real > fecha_hora_salida_prevista + INTERVAL 15 MINUTE AND importe_exceso = 0;")
[[ "$excesos" == "0" ]] && ok "ninguna salida tardía se quedó sin cobrar el exceso" || fallo "$excesos salidas tardías sin exceso"

borrado=$(docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" "$DB_NAME" -e "START TRANSACTION; DELETE FROM RESERVA WHERE id_reserva = 1; ROLLBACK;" 2>&1)
if grep -qi "no se borran" <<<"$borrado"; then
    ok "el trigger impide borrar una reserva"
else
    fallo "el trigger de borrado no actuó: $borrado"
fi

printf "\n4. Monitorización"
metrics=$(curl -k -s --max-time 10 "${APP}/metrics.php")
if grep -q "tarraco_reservas_activas" <<<"$metrics"; then
    ok "la aplicación expone métricas de negocio"
else
    fallo "no se encuentran métricas en /metrics.php"
fi
if grep -q "tarraco_http_requests_total" <<<"$metrics"; then
    ok "la aplicación expone contadores HTTP"
else
    fallo "no hay contadores HTTP en /metrics.php"
fi

code=$(http_code "http://localhost:9090/-/healthy")
[[ "$code" == "200" ]] && ok "Prometheus responde" || fallo "Prometheus responde $code"

code=$(http_code "http://localhost:3000/api/health")
[[ "$code" == "200" ]] && ok "Grafana responde" || fallo "Grafana responde $code"

code=$(http_code "http://localhost:${PMA_PORT}/")
[[ "$code" == "200" ]] && ok "phpMyAdmin responde en ${PMA_PORT}" || fallo "phpMyAdmin responde $code"

printf "\nRESULTADO: $((total-fallos))/$total pruebas correctas."
[[ "$fallos" -eq 0 ]] || exit 1
