#!/usr/bin/env python3
"""Comprobación estructural de PHP sin PHP.

Aquí no hay intérprete PHP (ni Docker con elevación), así que la sintaxis no se
puede validar con `php -l`. Este script hace lo que sí se puede hacer leyendo:
recorre cada archivo con un lexer mínimo que entiende cadenas, comentarios y
etiquetas `<?php ?>`, y comprueba que paréntesis, corchetes y llaves cierran en
el orden correcto y que el archivo termina en un estado limpio.

Lo que detecta: desequilibrios, cierres cruzados, cadenas sin cerrar y
comentarios de bloque sin terminar. Lo que NO detecta: errores de gramática
(una coma de más, un tipo mal escrito). Para eso hace falta `php -l`.

Uso:
    python tools/verifica-sintaxis.py [rutas...]
"""
from __future__ import annotations

import sys
from pathlib import Path

PAREJAS = {")": "(", "]": "[", "}": "{"}
ABRE = set(PAREJAS.values())


def revisa(fuente: str) -> list[str]:
    problemas: list[str] = []
    pila: list[tuple[str, int]] = []
    i = 0
    n = len(fuente)
    linea = 1

    while i < n:
        c = fuente[i]

        if c == "\n":
            linea += 1
            i += 1
            continue

        # Comentario de línea
        if fuente.startswith("//", i) or c == "#":
            while i < n and fuente[i] != "\n":
                i += 1
            continue

        # Comentario de bloque
        if fuente.startswith("/*", i):
            fin = fuente.find("*/", i + 2)
            if fin == -1:
                problemas.append(f"línea {linea}: comentario /* sin cerrar")
                return problemas
            linea += fuente.count("\n", i, fin)
            i = fin + 2
            continue

        # Cadena con comillas simples o dobles
        if c in ("'", '"'):
            comilla = c
            i += 1
            while i < n:
                if fuente[i] == "\\":
                    i += 2
                    continue
                if fuente[i] == comilla:
                    i += 1
                    break
                if fuente[i] == "\n":
                    linea += 1
                i += 1
            else:
                problemas.append(f"línea {linea}: cadena sin cerrar")
                return problemas
            continue

        if c in ABRE:
            pila.append((c, linea))
        elif c in PAREJAS:
            if not pila:
                problemas.append(f"línea {linea}: '{c}' sin apertura")
            else:
                abre, lin = pila.pop()
                if abre != PAREJAS[c]:
                    problemas.append(
                        f"línea {linea}: '{c}' cierra '{abre}' abierto en la línea {lin}"
                    )
        i += 1

    for abre, lin in pila:
        problemas.append(f"línea {lin}: '{abre}' sin cerrar")
    return problemas


def main(argv: list[str]) -> int:
    raiz = Path(__file__).resolve().parent.parent
    if len(argv) > 1:
        rutas = [Path(a) for a in argv[1:]]
    else:
        rutas = sorted(raiz.glob("app/**/*.php")) + sorted(raiz.glob("tests/*.php"))

    malos = 0
    for ruta in rutas:
        texto = ruta.read_text(encoding="utf-8", errors="replace")
        problemas = revisa(texto)
        if problemas:
            malos += 1
            print(f"FALLO {ruta}")
            for p in problemas:
                print(f"        {p}")

    print(f"\n{len(rutas)} archivos, {malos} con problemas estructurales.")
    return 1 if malos else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
