#!/usr/bin/env bash
# Genera el certificado autofirmado para tarraco.local.
# El contenedor también lo genera al arrancar si falta; este script sirve
# para tenerlo en el host antes del primer `docker compose up`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CRT="$ROOT/certs/tarraco.crt"
KEY="$ROOT/certs/tarraco.key"

mkdir -p "$ROOT/certs"

if [[ -s "$CRT" && -s "$KEY" ]]; then
    echo "El certificado ya existe: $CRT"
    exit 0
fi

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$KEY" -out "$CRT" \
    -subj "/C=ES/ST=Tarragona/L=Tarragona/O=Tarraco Parkings/CN=tarraco.local" \
    -addext "subjectAltName=DNS:tarraco.local,DNS:localhost,IP:127.0.0.1"

chmod 600 "$KEY"
echo "Certificado creado en $CRT"
