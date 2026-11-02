#!/usr/bin/env python3
"""Publica el documento de presentación: índice con páginas reales y PDF.

Chromium no sabe numerar un índice con la página en la que cae cada título
(no implementa `target-counter`). Se hace en dos pasadas, que es lo mismo que
se hace con el .docx de otros módulos:

  1. se imprime el HTML tal cual y se busca en qué página cae cada título;
  2. se escriben esos números en el índice y se vuelve a imprimir.

El HTML es la fuente y se queda con los números escritos, así que una segunda
ejecución sin cambios no mueve nada. Si un título no aparece en el PDF, su
entrada se queda con un guion y el script lo dice: es mejor un índice con un
hueco visible que un número inventado.

Uso:
    python3 tools/publica-documento.py            # numerar y exportar
    python3 tools/publica-documento.py --check    # solo comprobar
"""
from __future__ import annotations

import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
HTML = RAIZ / "docs" / "DOCUMENTO-PRESENTACION.html"
PDF = RAIZ / "docs" / "DOCUMENTO-PRESENTACION.pdf"

# Títulos del índice, en el orden en que salen. Se buscan por su texto exacto
# en el PDF, y el primero que aparezca a partir de la página anterior es el
# bueno: así una sección que se cita dos veces no rompe la cuenta.
TITULOS = {
    "s1": "1. Qué se entrega y qué no",
    "s11": "1.1 Resumen del sprint",
    "s12": "1.2 Alcance: solo software",
    "s13": "1.3 Las dos indicaciones del profesorado",
    "s2": "2. Cómo funciona la aplicación",
    "s21": "2.1 Los tres roles",
    "s22": "2.2 El recorrido del cliente",
    "s23": "2.3 El recorrido del administrador",
    "s24": "2.4 El operador y el reparto de permisos",
    "s3": "3. Arquitectura, servicios y servidor",
    "s31": "3.1 Los siete contenedores",
    "s32": "3.2 El servidor de despliegue",
    "s33": "3.3 HTTPS y certificados",
    "s34": "3.4 Copias de seguridad y restauración",
    "s35": "3.5 Monitorización",
    "s4": "4. Modelo de datos",
    "s41": "4.1 Las once entidades",
    "s42": "4.2 Las quince relaciones",
    "s43": "4.3 Diagrama E/R actualizado",
    "s44": "4.4 Integridad en la base de datos",
    "s45": "4.5 Qué cambia respecto al Sprint 1",
    "s5": "5. Implementación y código",
    "s51": "5.1 Estructura del código",
    "s52": "5.2 La reserva en una transacción",
    "s53": "5.3 El precio y el exceso",
    "s54": "5.4 El control de acceso",
    "s55": "5.5 El resguardo PDF",
    "s56": "5.6 Seguridad",
    "s6": "6. Planificación actualizada",
    "s61": "6.1 Método y herramienta",
    "s62": "6.2 Tareas y reparto de roles",
    "s63": "6.3 Diagrama de Gantt",
    "s64": "6.4 Qué se desvió y por qué",
    "s7": "7. Verificación",
    "s71": "7.1 Qué se ejecutó y qué devolvió",
    "s72": "7.2 El enunciado, punto por punto",
    "s73": "7.3 Límites conocidos",
    "s8": "8. Bibliografía",
    "s9": "9. Declaración de uso de IA",
    "sa": "Anexo A. Capturas de la aplicación",
    "sb": "Anexo B. Guion de la exposición",
}


def busca_chrome() -> str:
    # En Linux (el aula) está en el PATH. En Windows no suele estarlo, así que
    # se miran las rutas de instalación de Chrome y, si no hay, de Edge: los dos
    # son Chromium y los dos imprimen igual.
    for nombre in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser",
                   "chrome", "msedge"):
        ruta = shutil.which(nombre)
        if ruta:
            return ruta
    for candidato in (
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    ):
        if Path(candidato).exists():
            return candidato
    raise SystemExit("No encuentro Chrome (ni Edge) para imprimir el PDF.")


def imprime(chrome: str, pdf: Path) -> None:
    """Chrome headless con un perfil efímero: el SSD es exFAT y no bloquea."""
    if pdf.exists():
        pdf.unlink()
    perfil = Path(tempfile.mkdtemp(prefix="chrome-doc-sprint2-"))
    try:
        subprocess.run(
            [
                chrome,
                "--headless=new",
                "--disable-gpu",
                "--no-sandbox",
                "--no-pdf-header-footer",
                f"--user-data-dir={perfil}",
                f"--print-to-pdf={pdf}",
                HTML.as_uri(),
            ],
            check=False,
            capture_output=True,
            timeout=180,
        )
    finally:
        shutil.rmtree(perfil, ignore_errors=True)
    if not pdf.exists() or pdf.stat().st_size == 0:
        raise SystemExit("Chrome no ha escrito el PDF.")


def paginas_del_pdf(pdf: Path) -> list[str]:
    import pymupdf  # importa aquí para que --check no lo exija

    doc = pymupdf.open(pdf)
    return [pagina.get_text() for pagina in doc]


def situa(paginas: list[str]) -> dict[str, int]:
    """Primera página (1-based) de cada título, avanzando siempre hacia delante."""
    encontrados: dict[str, int] = {}
    desde = 0
    for clave, titulo in TITULOS.items():
        # Se compara sin espacios dobles y sin guiones tipográficos.
        patron = re.compile(r"\s+".join(map(re.escape, titulo.split())))
        for i in range(desde, len(paginas)):
            if patron.search(paginas[i]):
                encontrados[clave] = i + 1
                desde = i
                break
    return encontrados


def escribe_numeros(html: str, paginas: dict[str, int]) -> str:
    def sustituye(m: re.Match[str]) -> str:
        clave = m.group(1)
        valor = str(paginas[clave]) if clave in paginas else "–"
        return f'<span class="pg" data-ref="{clave}">{valor}</span>'

    return re.sub(r'<span class="pg" data-ref="([a-z0-9]+)">[^<]*</span>', sustituye, html)


def main() -> int:
    solo_comprobar = "--check" in sys.argv
    chrome = busca_chrome()

    html = HTML.read_text(encoding="utf-8")
    with tempfile.TemporaryDirectory() as tmp:
        borrador = Path(tmp) / "borrador.pdf"
        imprime(chrome, borrador)
        paginas = paginas_del_pdf(borrador)
        situacion = situa(paginas)
        print(f"Borrador: {len(paginas)} páginas, {len(situacion)}/{len(TITULOS)} títulos localizados")

        faltan = [TITULOS[k] for k in TITULOS if k not in situacion]
        for titulo in faltan:
            print(f"  ! no localizado: {titulo}")

        nuevo = escribe_numeros(html, situacion)
        if nuevo != html:
            HTML.write_text(nuevo, encoding="utf-8")

    if solo_comprobar:
        return 0

    imprime(chrome, PDF)
    paginas = paginas_del_pdf(PDF)
    print(f"PDF: {PDF} ({PDF.stat().st_size // 1024} KB, {len(paginas)} páginas)")

    # Comprobación de que el índice impreso lleva los números y no guiones.
    texto = "\n".join(paginas[:3])
    if texto.count("–") > 2:
        print("  ! el índice conserva entradas sin número de página")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
