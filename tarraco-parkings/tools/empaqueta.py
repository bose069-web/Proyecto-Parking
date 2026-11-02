#!/usr/bin/env python3
"""Empaqueta el proyecto en el ZIP de entrega.

Se hace con un guion y no a mano para que el paquete sea el mismo siempre y
para que lo que NO debe salir no salga por descuido:

  - `.env` lleva credenciales, aunque sean de laboratorio. Se entrega
    `.env.example`, que es la plantilla.
  - Los certificados autofirmados se generan al arrancar el contenedor.
  - La caché de Python (`__pycache__`, `*.pyc`) es basura de las pruebas.
  - Copias de seguridad y registros son estado de una máquina concreta.

Todo el contenido va bajo `tarraco-parkings/`, que es la raíz que pide la guía
del proyecto: al descomprimir sale una carpeta, no veinte archivos sueltos.

Uso:
    python3 tools/empaqueta.py                 # crea el ZIP de al lado del proyecto
    python3 tools/empaqueta.py --sin-video     # variante ligera, sin el vídeo
    python3 tools/empaqueta.py --destino X.zip
"""
from __future__ import annotations

import sys
import zipfile
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PREFIJO = "tarraco-parkings"
DESTINO_POR_DEFECTO = RAIZ.parent / "tarraco-parkings-sprint2.zip"
DESTINO_SIN_VIDEO = RAIZ.parent / "tarraco-parkings-sprint2-sin-video.zip"
VIDEO = "docs/VIDEO-DEMO.mp4"

EXCLUIDOS_EXACTOS = {
    ".env",
}
EXCLUIDOS_DIR = {
    "__pycache__",
    ".git",
    "node_modules",
    "vendor",
}
EXCLUIDOS_SUFIJO = {
    ".pyc", ".pyo", ".log", ".swp",
}
# Certificados y copias: se generan, no se entregan.
EXCLUIDOS_CERTS = {".crt", ".key", ".pem"}


def excluir(ruta: Path) -> bool:
    rel = ruta.relative_to(RAIZ)
    partes = set(rel.parts)
    if EXCLUIDOS_DIR & partes:
        return True
    if ruta.name in EXCLUIDOS_EXACTOS:
        return True
    if ruta.suffix.lower() in EXCLUIDOS_SUFIJO:
        return True
    if ruta.suffix.lower() in EXCLUIDOS_CERTS and rel.parts[:1] == ("certs",):
        return True
    if rel.parts[:1] == ("backups",) and ruta.suffix.lower() in {".sql", ".gz"}:
        return True
    return False


def main() -> int:
    sin_video = "--sin-video" in sys.argv
    destino = DESTINO_SIN_VIDEO if sin_video else DESTINO_POR_DEFECTO
    if "--destino" in sys.argv:
        destino = Path(sys.argv[sys.argv.index("--destino") + 1]).resolve()

    archivos = sorted(
        p for p in RAIZ.rglob("*")
        if p.is_file() and not excluir(p)
        and not (sin_video and p.relative_to(RAIZ).as_posix() == VIDEO)
    )
    if not archivos:
        print("No hay nada que empaquetar.", file=sys.stderr)
        return 1

    if destino.exists():
        destino.unlink()

    with zipfile.ZipFile(destino, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for p in archivos:
            rel = p.relative_to(RAIZ).as_posix()
            z.write(p, f"{PREFIJO}/{rel}")

    crudo = sum(p.stat().st_size for p in archivos)
    print(f"ZIP: {destino}")
    print(f"  {len(archivos)} archivos, {crudo / 1024 / 1024:.2f} MB sin comprimir, "
          f"{destino.stat().st_size / 1024 / 1024:.2f} MB en el ZIP")
    print(f"  raíz dentro del ZIP: {PREFIJO}/")
    if sin_video:
        print(f"  sin {VIDEO}: se entrega aparte")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
