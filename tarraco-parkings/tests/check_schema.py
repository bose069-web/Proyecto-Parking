#!/usr/bin/env python3
"""Comprueba la estructura de db/init.sql sin necesidad de un servidor MySQL.

No sustituye a `docker compose up`: comprueba lo que se puede comprobar
leyendo el texto. Valida que las tablas existen, que cada clave foránea
apunta a una tabla y a una columna que existen, que los índices y los
INSERT no nombran columnas inventadas y que el orden de borrado es
coherente con las dependencias.

Uso:
    python3 tests/check_schema.py [ruta/init.sql]

Sale con código 0 si todo cuadra y 1 si hay algún error.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

DEFAULT_SQL = Path(__file__).resolve().parent.parent / "db" / "init.sql"


def strip_comments(sql: str) -> str:
    """Quita comentarios de línea y de bloque, respetando las cadenas."""
    out = []
    i = 0
    in_str = False
    quote = ""
    while i < len(sql):
        ch = sql[i]
        if in_str:
            out.append(ch)
            if ch == quote and (i == 0 or sql[i - 1] != "\\"):
                in_str = False
            i += 1
            continue
        if ch in ("'", '"'):
            in_str = True
            quote = ch
            out.append(ch)
            i += 1
            continue
        if sql.startswith("--", i):
            while i < len(sql) and sql[i] != "\n":
                i += 1
            continue
        if sql.startswith("/*", i):
            end = sql.find("*/", i + 2)
            i = len(sql) if end == -1 else end + 2
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def split_top_level(body: str) -> list[str]:
    """Parte por comas de primer nivel, sin romper DECIMAL(10,2) ni ENUM(...)."""
    parts, buf, depth = [], [], 0
    in_str = False
    quote = ""
    for ch in body:
        if in_str:
            buf.append(ch)
            if ch == quote:
                in_str = False
            continue
        if ch in ("'", '"'):
            in_str = True
            quote = ch
            buf.append(ch)
        elif ch == "(":
            depth += 1
            buf.append(ch)
        elif ch == ")":
            depth -= 1
            buf.append(ch)
        elif ch == "," and depth == 0:
            parts.append("".join(buf).strip())
            buf = []
        else:
            buf.append(ch)
    if "".join(buf).strip():
        parts.append("".join(buf).strip())
    return parts


def parse_tables(sql: str) -> dict:
    tables: dict[str, dict] = {}
    pattern = re.compile(
        r"CREATE\s+TABLE\s+(\w+)\s*\((.*?)\)\s*ENGINE\s*=", re.IGNORECASE | re.DOTALL
    )
    for name, body in pattern.findall(sql):
        cols, pk, uniques, fks, indexes, checks = [], [], [], [], [], []
        for part in split_top_level(body):
            upper = part.upper()
            if upper.startswith("PRIMARY KEY"):
                m = re.search(r"\((.*?)\)", part)
                pk = [c.strip() for c in m.group(1).split(",")] if m else []
            elif "FOREIGN KEY" in upper:
                m = re.search(
                    r"FOREIGN\s+KEY\s*\((\w+)\)\s*REFERENCES\s+(\w+)\s*\((\w+)\)",
                    part,
                    re.IGNORECASE,
                )
                if not m:
                    fks.append({"raw": part, "error": "FK no reconocida"})
                else:
                    fks.append(
                        {
                            "col": m.group(1),
                            "ref_table": m.group(2),
                            "ref_col": m.group(3),
                            "raw": part,
                        }
                    )
            elif upper.startswith(("UNIQUE KEY", "UNIQUE INDEX")):
                m = re.search(r"\((.*?)\)", part)
                cols_u = [c.strip() for c in m.group(1).split(",")] if m else []
                uniques.append(cols_u)
                indexes.append(cols_u)
            elif upper.startswith(("KEY ", "INDEX ")):
                m = re.search(r"\((.*?)\)", part)
                cols_i = [c.strip() for c in m.group(1).split(",")] if m else []
                indexes.append(cols_i)
            elif upper.startswith("CONSTRAINT") and "CHECK" in upper:
                checks.append(part)
            elif upper.startswith(("CONSTRAINT", "FULLTEXT", "SPATIAL")):
                continue
            else:
                m = re.match(r"`?(\w+)`?", part)
                if m:
                    cols.append(m.group(1))
        tables[name.upper()] = {
            "cols": cols,
            "pk": pk,
            "uniques": uniques,
            "fks": fks,
            "indexes": indexes,
            "checks": checks,
        }
    return tables


def parse_inserts(sql: str) -> list[tuple[str, list[str], int]]:
    rows = []
    for m in re.finditer(
        r"INSERT\s+INTO\s+(\w+)\s*\(([^)]*)\)\s*VALUES(.*?);",
        sql,
        re.IGNORECASE | re.DOTALL,
    ):
        table = m.group(1).upper()
        cols = [c.strip().strip("`") for c in m.group(2).split(",")]
        values = m.group(3)
        # Cuenta tuplas de primer nivel: cada "(" que abre una fila tras una coma o el inicio.
        depth, in_str, quote, row_count = 0, False, "", 0
        for i, ch in enumerate(values):
            if in_str:
                if ch == quote:
                    in_str = False
                continue
            if ch in ("'", '"'):
                in_str = True
                quote = ch
            elif ch == "(":
                if depth == 0:
                    row_count += 1
                depth += 1
            elif ch == ")":
                depth -= 1
        rows.append((table, cols, row_count))
    return rows


def main() -> int:
    path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_SQL
    raw = path.read_text(encoding="utf-8")
    sql = strip_comments(raw)
    errors: list[str] = []
    warnings: list[str] = []

    tables = parse_tables(sql)
    expected = 11
    if len(tables) != expected:
        errors.append(f"Se esperaban {expected} tablas y hay {len(tables)}: {sorted(tables)}")

    # 1. Clave primaria en todas las tablas.
    for name, t in tables.items():
        if not t["pk"]:
            errors.append(f"{name}: no tiene PRIMARY KEY")
        for col in t["pk"]:
            if col not in t["cols"]:
                errors.append(f"{name}: la PK nombra {col!r}, que no es una columna")

    # 2. Claves foráneas.
    for name, t in tables.items():
        for fk in t["fks"]:
            if "error" in fk:
                errors.append(f"{name}: {fk['error']}: {fk['raw']}")
                continue
            ref = fk["ref_table"].upper()
            if ref not in tables:
                errors.append(f"{name}.{fk['col']}: referencia a tabla inexistente {ref}")
                continue
            if fk["col"] not in t["cols"]:
                errors.append(f"{name}.{fk['col']}: la FK no es una columna de {name}")
            target = tables[ref]
            if fk["ref_col"] not in target["cols"]:
                errors.append(
                    f"{name}.{fk['col']}: referencia a {ref}.{fk['ref_col']}, que no existe"
                )
            else:
                key_cols = [target["pk"]] + [u for u in target["uniques"]]
                if not any(c and c[0] == fk["ref_col"] for c in key_cols):
                    warnings.append(
                        f"{name}.{fk['col']} -> {ref}.{fk['ref_col']}: la columna destino no es PK ni UNIQUE"
                    )

    # 3. Índices que nombran columnas inexistentes.
    for name, t in tables.items():
        for idx in t["indexes"]:
            for col in idx:
                if col not in t["cols"]:
                    errors.append(f"{name}: índice sobre columna inexistente {col!r}")

    # 4. INSERT con columnas inexistentes y recuento de filas.
    counts: dict[str, int] = {}
    for table, cols, n in parse_inserts(sql):
        if table not in tables:
            errors.append(f"INSERT INTO {table}: la tabla no existe")
            continue
        for col in cols:
            if col not in tables[table]["cols"]:
                errors.append(f"INSERT INTO {table}: columna inexistente {col!r}")
        if len(cols) != len(set(cols)):
            errors.append(f"INSERT INTO {table}: columnas repetidas en la lista")
        counts[table] = counts.get(table, 0) + n

    # 5. Orden de DROP coherente con el de CREATE.
    drop_m = re.search(r"((?:DROP TABLE IF EXISTS \w+;\s*)+)", sql, re.IGNORECASE)
    dropped = re.findall(r"DROP TABLE IF EXISTS (\w+)", drop_m.group(1), re.IGNORECASE) if drop_m else []
    if set(x.upper() for x in dropped) != set(tables):
        errors.append(
            f"DROP y CREATE no coinciden: faltan {sorted(set(tables) - set(x.upper() for x in dropped))}, "
            f"sobran {sorted(set(x.upper() for x in dropped) - set(tables))}"
        )
    # Un DROP de una tabla con hijos antes que el hijo dejaría el borrado a medias.
    created_order = [m.group(1).upper() for m in re.finditer(r"CREATE\s+TABLE\s+(\w+)", sql, re.I)]
    for name, t in tables.items():
        for fk in t["fks"]:
            if "ref_table" not in fk:
                continue
            ref = fk["ref_table"].upper()
            if name in dropped and ref in dropped:
                if dropped.index(name) > dropped.index(ref):
                    errors.append(
                        f"DROP: {name} (hija de {ref}) se borra después que su padre"
                    )

    # 6. Reglas del Sprint 2: nada de hardware en el modelo.
    forbidden = {"DISPOSITIVO", "DISPOSITIU", "BARRERA", "LECTOR", "CAMARA", "CAMERA", "SWITCH"}
    for name in tables:
        if name in forbidden:
            errors.append(f"{name}: el modelo no debe contener entidades de hardware")
    for name, t in tables.items():
        for col in t["cols"]:
            if any(word in col.upper() for word in ("DISPOSITIVO", "BARRERA", "BARRERA_", "CAMARA")):
                errors.append(f"{name}.{col}: columna de hardware prohibida por los profesores")

    # 7. Recuento mínimo de datos de prueba.
    minimums = {"PARKING": 3, "TARIFA": 5, "ADMINISTRADOR": 2, "CLIENTE": 5, "VEHICULO": 10, "RESERVA": 20}
    for table, minimum in minimums.items():
        if counts.get(table, 0) < minimum:
            errors.append(f"{table}: {counts.get(table, 0)} filas, se esperaban al menos {minimum}")

    # Salida.
    print(f"Archivo: {path}")
    print(f"Tablas: {len(tables)} · INSERTs: {sum(counts.values())} filas en {len(counts)} tablas")
    for name in sorted(tables):
        t = tables[name]
        print(
            f"  {name:<20} {len(t['cols']):>2} columnas · PK {','.join(t['pk'])} · "
            f"{len(t['fks'])} FK · {counts.get(name, 0)} filas"
        )
    if warnings:
        print("\nAvisos:")
        for w in warnings:
            print(f"  ! {w}")
    if errors:
        print("\nERRORES:")
        for e in errors:
            print(f"  x {e}")
        return 1
    print("\nRESULTADO: esquema estructuralmente válido.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
