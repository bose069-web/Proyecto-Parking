#!/bin/sh
# Arranque del contenedor: genera el certificado autofirmado si no existe.
# Así `docker compose up -d` funciona a la primera, sin pasos manuales.
set -eu

CRT=/etc/apache2/certs/tarraco.crt
KEY=/etc/apache2/certs/tarraco.key

if [ ! -s "$CRT" ] || [ ! -s "$KEY" ]; then
    echo "[tarraco] Generando certificado autofirmado para tarraco.local..."
    mkdir -p /etc/apache2/certs
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$KEY" -out "$CRT" \
        -subj "/C=ES/ST=Tarragona/L=Tarragona/O=Tarraco Parkings/CN=tarraco.local" \
        -addext "subjectAltName=DNS:tarraco.local,DNS:localhost,IP:127.0.0.1"
    # El directorio puede ser un bind mount de exFAT o NTFS (Docker Desktop
    # sobre Windows), donde chmod no siempre está permitido. El proceso de
    # Apache lee la clave como root, así que un fallo aquí no debe impedir
    # el arranque: con set -e, un chmod fallido mataría el contenedor.
    chmod 600 "$KEY" 2>/dev/null || true
    echo "[tarraco] Certificado creado."
else
    echo "[tarraco] Certificado existente; no se toca."
fi

exec "$@"
