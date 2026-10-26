#!/usr/bin/env python3
"""Comprueba enlaces internos, assets y referencias entre archivos.

No abre el navegador: revisa que cada href/action/src de la aplicación
apunte a un archivo que existe, y que cada enlace relativo de la
documentación resuelva. Detecta el error típico de mover una página y
dejar el menú apuntando a la ruta vieja.

Uso:
    python3 tests/check_links.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PUBLIC = ROOT / "app" / "public"
DOCS = ROOT / "docs"

errores: list[str] = []
revisados = 0


def check_public_ref(origen: Path, ref: str) -> None:
    global revisados
    if ref.startswith(("http://", "https://", "mailto:", "tel:", "#", "javascript:")):
        return

    limpio = ref.split("?")[0].split("#")[0]
    if not limpio.startswith("/"):
        return

    revisados += 1
    destino = PUBLIC / limpio.lstrip("/")
    if limpio.endswith("/"):
        destino = destino / "index.php"

    if not destino.exists():
        errores.append(f"{origen.relative_to(ROOT)}: enlace roto {ref} -> {destino.relative_to(ROOT)}")


def check_doc_ref(origen: Path, ref: str) -> None:
    global revisados
    if ref.startswith(("http://", "https://", "mailto:", "#")):
        return

    limpio = ref.split("#")[0]
    if not limpio:
        return

    revisados += 1
    destino = (origen.parent / limpio).resolve()
    if not destino.exists():
        errores.append(f"{origen.relative_to(ROOT)}: referencia rota {ref}")


print("Enlaces de la aplicación")
for php in sorted(PUBLIC.rglob("*.php")) + sorted((ROOT / "app" / "templates").glob("*.php")):
    texto = php.read_text(encoding="utf-8")
    for m in re.finditer(r'(?:href|action|src)="([^"]+)"', texto):
        check_public_ref(php, m.group(1))

print("Referencias de la documentación")
for md in sorted(DOCS.glob("*.md")) + [ROOT / "README.md"]:
    texto = md.read_text(encoding="utf-8")
    # [texto](ruta) e ![alt](ruta)
    for m in re.finditer(r"!?\[[^\]]*\]\(([^)]+)\)", texto):
        check_doc_ref(md, m.group(1).strip())

print("Referencias de los scripts")
for script in sorted((ROOT / "scripts").glob("*.sh")):
    texto = script.read_text(encoding="utf-8")
    for m in re.finditer(r"\./([\w./-]+\.(?:yml|sql|crt|key|sh|php))", texto):
        if not (ROOT / m.group(1)).exists():
            errores.append(f"{script.relative_to(ROOT)}: referencia rota {m.group(1)}")

print(f"\n{revisados} referencias revisadas.")
if errores:
    print("\nERRORES:")
    for e in errores:
        print(f"  x {e}")
    sys.exit(1)

print("RESULTADO: todas las referencias internas resuelven.")
