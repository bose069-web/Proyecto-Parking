#!/usr/bin/env bash
# Copia de seguridad de la base de datos con mysqldump dentro del contenedor.
# Uso: bash scripts/backup.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
    echo "Falta .env. Copia .env.example y ajusta las credenciales." >&2
    exit 1
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

mkdir -p backups
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="backups/tarraco_${STAMP}.sql"

docker compose exec -T db mariadb-dump \
    --user=root \
    --password="$DB_ROOT_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_NAME" > "$OUT"

gzip -f "$OUT"
echo "Copia creada: ${OUT}.gz"
