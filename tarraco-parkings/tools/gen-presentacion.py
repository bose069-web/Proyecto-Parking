#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""gen-presentacion.py — genera el deck del Sprint 2 (16:9, 18 diapositivas).

    PYTHONPATH=<SSD>/Maquinas/_pylib python3 tools/gen-presentacion.py

Escribe docs/PRESENTACION.pptx y, si hay soffice, docs/PRESENTACION.pdf.
El guion y las cifras salen de docs/PRESENTACION.md; aqui solo esta la puesta
en pagina. Cada cifra está comprobada contra el proyecto: 11 tablas, 15 FK,
131 filas, 22 pruebas de cálculo, 99 consultas, 96 enlaces, 37 archivos PHP,
19/19 en el smoke test y 53/53 en la prueba de extremo a extremo.
"""
from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.util import Emu, Inches, Pt

RAIZ = Path(__file__).resolve().parent.parent
IMG = RAIZ / "docs" / "img"
SALIDA_PPTX = RAIZ / "docs" / "PRESENTACION.pptx"
SALIDA_PDF = RAIZ / "docs" / "PRESENTACION.pdf"

# ------------------------------------------------------------------ paleta
INK = RGBColor(0x1B, 0x2A, 0x32)
INK_SOFT = RGBColor(0x3A, 0x4C, 0x55)
TERRA = RGBColor(0xC4, 0x61, 0x3C)
TERRA_CLARO = RGBColor(0xE8, 0xA9, 0x8C)
SAND = RGBColor(0xF6, 0xF1, 0xE8)
SAND_DARK = RGBColor(0xE4, 0xDA, 0xC8)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
MUTED = RGBColor(0x6B, 0x78, 0x7D)
GREEN = RGBColor(0x2F, 0x7A, 0x4F)

FUENTE = "Calibri"

W = Inches(13.333)
H = Inches(7.5)
MARGEN = Inches(0.85)


def nueva_presentacion() -> Presentation:
    prs = Presentation()
    prs.slide_width = W
    prs.slide_height = H
    return prs


def slide_en_blanco(prs: Presentation):
    return prs.slides.add_slide(prs.slide_layouts[6])


def rect(slide, x, y, w, h, color, shape=MSO_SHAPE.RECTANGLE, line=None):
    sh = slide.shapes.add_shape(shape, x, y, w, h)
    sh.fill.solid()
    sh.fill.fore_color.rgb = color
    if line is None:
        sh.line.fill.background()
    else:
        sh.line.color.rgb = line
        sh.line.width = Pt(1)
    sh.shadow.inherit = False
    # sin el bloque p:style, LibreOffice deja de pintar la sombra del tema
    estilo = sh._element.find('{http://schemas.openxmlformats.org/presentationml/2006/main}style')
    if estilo is not None:
        sh._element.remove(estilo)
    return sh


def texto(slide, x, y, w, h, contenido, size=18, color=INK, bold=False,
          align=PP_ALIGN.LEFT, anchor=MSO_ANCHOR.TOP, italic=False,
          espacio=1.0, fuente=FUENTE):
    tb = slide.shapes.add_textbox(x, y, w, h)
    tf = tb.text_frame
    tf.word_wrap = True
    tf.margin_left = 0
    tf.margin_right = 0
    tf.margin_top = 0
    tf.margin_bottom = 0
    tf.vertical_anchor = anchor
    lineas = contenido if isinstance(contenido, list) else [contenido]
    for i, linea in enumerate(lineas):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.alignment = align
        p.line_spacing = espacio
        r = p.add_run()
        r.text = linea
        r.font.size = Pt(size)
        r.font.bold = bold
        r.font.italic = italic
        r.font.color.rgb = color
        r.font.name = fuente
    return tb


def cabecera(slide, kicker, titulo, entradilla=None):
    """Cabecera de contenido: filete, kicker, titulo y entradilla opcional."""
    rect(slide, MARGEN, Inches(0.62), Inches(0.55), Pt(3.5), TERRA)
    texto(slide, MARGEN, Inches(0.78), Inches(9.5), Inches(0.3), kicker.upper(),
          size=10.5, color=TERRA, bold=True)
    texto(slide, MARGEN, Inches(1.12), Inches(11.6), Inches(0.9), titulo,
          size=33, color=INK, bold=True, espacio=0.95)
    if entradilla:
        texto(slide, MARGEN, Inches(2.02), Inches(10.6), Inches(0.6), entradilla,
              size=15.5, color=MUTED)
        return Inches(2.86)
    return Inches(2.32)


def pie(slide, numero, total=18):
    rect(slide, MARGEN, H - Inches(0.72), W - 2 * MARGEN, Pt(0.75), SAND_DARK)
    texto(slide, MARGEN, H - Inches(0.58), Inches(6), Inches(0.25),
          "Tarraco Parkings · Sprint 2 · ASIX2 0379", size=9, color=MUTED)
    texto(slide, W - MARGEN - Inches(1.2), H - Inches(0.58), Inches(1.2), Inches(0.25),
          f"{numero} / {total}", size=9, color=MUTED, align=PP_ALIGN.RIGHT)


def vinetas(slide, x, y, w, items, size=17, gap=0.62, color=INK, ancho_marca=0.16,
            espacio_linea=1.15):
    """Lista con marca cuadrada. Cada item admite (texto, negrita_inicial)."""
    for i, item in enumerate(items):
        yy = y + Inches(gap * i)
        rect(slide, x, yy + Inches(0.11), Inches(0.075), Inches(0.075), TERRA,
             shape=MSO_SHAPE.RECTANGLE)
        if isinstance(item, tuple):
            cabeza, resto = item
        else:
            cabeza, resto = None, item
        tb = slide.shapes.add_textbox(x + Inches(ancho_marca + 0.16), yy,
                                      w - Inches(ancho_marca + 0.16), Inches(gap))
        tf = tb.text_frame
        tf.word_wrap = True
        tf.margin_left = 0
        tf.margin_right = 0
        tf.margin_top = 0
        tf.margin_bottom = 0
        p = tf.paragraphs[0]
        p.line_spacing = espacio_linea
        if cabeza:
            r = p.add_run()
            r.text = cabeza
            r.font.size = Pt(size)
            r.font.bold = True
            r.font.color.rgb = INK
            r.font.name = FUENTE
        r = p.add_run()
        r.text = resto
        r.font.size = Pt(size)
        r.font.color.rgb = color
        r.font.name = FUENTE


def imagen(slide, ruta: Path, x, y, w=None, h=None, borde=True, pie_txt=None):
    """Coloca una imagen conservando proporción dentro de la caja dada."""
    from PIL import Image

    with Image.open(ruta) as im:
        iw, ih = im.size
    ratio = iw / ih
    if w is not None and h is not None:
        if w / h > ratio:
            hh = h
            ww = Emu(int(h * ratio))
        else:
            ww = w
            hh = Emu(int(w / ratio))
        xx = x + Emu(int((w - ww) / 2))
        yy = y + Emu(int((h - hh) / 2))
    elif w is not None:
        ww, hh, xx, yy = w, Emu(int(w / ratio)), x, y
    else:
        hh, ww, xx, yy = h, Emu(int(h * ratio)), x, y
    if borde:
        rect(slide, xx - Emu(9525), yy - Emu(9525), ww + Emu(19050), hh + Emu(19050), WHITE)
    pic = slide.shapes.add_picture(str(ruta), xx, yy, ww, hh)
    if borde:
        pic.line.color.rgb = SAND_DARK
        pic.line.width = Pt(0.75)
    if pie_txt:
        texto(slide, xx, yy + hh + Inches(0.10), ww, Inches(0.3), pie_txt,
              size=9.5, color=MUTED, italic=True)
    return pic


def tabla(slide, x, y, w, filas, cabeceras, anchos, size=9.5, alto_fila=0.255,
          color_cab=INK, color_texto=INK_SOFT, zebra=SAND, fuente=FUENTE):
    """Tabla simple con cabecera oscura y filas alternas."""
    ncol = len(cabeceras)
    nfil = len(filas) + 1
    gt = slide.shapes.add_table(nfil, ncol, x, y, w, Inches(alto_fila * nfil)).table
    for i, a in enumerate(anchos):
        gt.columns[i].width = Emu(int(a))
    for f in range(nfil):
        gt.rows[f].height = Inches(alto_fila)
    for c, cab in enumerate(cabeceras):
        cel = gt.cell(0, c)
        cel.text = cab
        cel.fill.solid()
        cel.fill.fore_color.rgb = color_cab
        cel.margin_left = Inches(0.08)
        cel.margin_right = Inches(0.06)
        cel.margin_top = Inches(0.02)
        cel.margin_bottom = Inches(0.02)
        cel.vertical_anchor = MSO_ANCHOR.MIDDLE
        p = cel.text_frame.paragraphs[0]
        r = p.runs[0]
        r.font.size = Pt(size - 1)
        r.font.bold = True
        r.font.color.rgb = WHITE
        r.font.name = fuente
    for f, fila in enumerate(filas, start=1):
        for c, val in enumerate(fila):
            cel = gt.cell(f, c)
            cel.text = val
            cel.fill.solid()
            cel.fill.fore_color.rgb = WHITE if f % 2 else zebra
            cel.margin_left = Inches(0.08)
            cel.margin_right = Inches(0.06)
            cel.margin_top = Inches(0.02)
            cel.margin_bottom = Inches(0.02)
            cel.vertical_anchor = MSO_ANCHOR.MIDDLE
            p = cel.text_frame.paragraphs[0]
            r = p.runs[0]
            r.font.size = Pt(size)
            r.font.color.rgb = color_texto
            r.font.name = fuente
            r.font.bold = (c == ncol - 1)
    return gt


# ==================================================================== slides
def s01_portada(prs):
    s = slide_en_blanco(prs)
    rect(s, 0, 0, W, H, INK)
    # banda terracota
    rect(s, 0, 0, Inches(0.22), H, TERRA)
    # motivo de plazas de aparcamiento, muy tenue, en la esquina
    for fila in range(2):
        for col in range(3):
            rect(s, W - Inches(3.15) + Emu(int(col * 0.95 * 914400)),
                 Inches(5.05) + Emu(int(fila * 0.95 * 914400)),
                 Inches(0.72), Inches(0.72), RGBColor(0x24, 0x37, 0x40))

    texto(s, MARGEN, Inches(1.5), Inches(9), Inches(0.35),
          "ASIX2 · 0379 PROYECTO · GRUPO 6", size=11.5, color=TERRA_CLARO, bold=True)
    texto(s, MARGEN, Inches(2.05), Inches(10.5), Inches(2.1),
          ["Tarraco Parkings", "Sprint 2"], size=64, color=WHITE, bold=True, espacio=0.92)
    rect(s, MARGEN, Inches(4.42), Inches(1.05), Pt(4), TERRA)
    texto(s, MARGEN, Inches(4.78), Inches(9.4), Inches(0.9),
          "De la planificación a la aplicación que se despliega y se prueba",
          size=21, color=RGBColor(0xC9, 0xD4, 0xD6))
    texto(s, MARGEN, Inches(6.28), Inches(11), Inches(0.7),
          ["Pere Brau · Dhruva Mehta · Boubacar Seck",
           "Curso 2026-2027 · Entrega del 22 de noviembre de 2026"],
          size=12.5, color=RGBColor(0x93, 0xA5, 0xA9), espacio=1.25)
    return s


def s02_cambio(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Punto de partida", "Qué cambia respecto al Sprint 1",
                 "El diseño ya estaba aprobado. Lo que faltaba era convertirlo en algo que se ejecuta.")
    # dos columnas comparativas
    cw = Inches(5.55)
    rect(s, MARGEN, y, cw, Inches(3.05), SAND)
    texto(s, MARGEN + Inches(0.35), y + Inches(0.28), cw - Inches(0.7), Inches(0.35),
          "SPRINT 1", size=11, color=MUTED, bold=True)
    texto(s, MARGEN + Inches(0.35), y + Inches(0.72), cw - Inches(0.7), Inches(2.1),
          "Respondía a «qué tiene que hacer el sistema». Documentos, casos de uso y modelo entidad-relación.",
          size=18, color=INK_SOFT, espacio=1.22)

    x2 = MARGEN + cw + Inches(0.5)
    rect(s, x2, y, cw, Inches(3.05), INK)
    texto(s, x2 + Inches(0.35), y + Inches(0.28), cw - Inches(0.7), Inches(0.35),
          "SPRINT 2", size=11, color=TERRA_CLARO, bold=True)
    texto(s, x2 + Inches(0.35), y + Inches(0.72), cw - Inches(0.7), Inches(2.1),
          "Responde a «cómo está construido y cómo se ejecuta». Código, base de datos, contenedores y monitorización.",
          size=18, color=WHITE, espacio=1.22)

    texto(s, MARGEN, y + Inches(3.42), Inches(11.4), Inches(0.8),
          "No se empieza de cero: el modelo del Sprint 1 es la base, con dos correcciones que se explican en la diapositiva 7.",
          size=14.5, color=MUTED, italic=True)
    pie(s, 2)
    return s


def s03_alcance(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Alcance", "Solo software",
                 "La indicación de los profesores después del Sprint 1 fue retirar todo el hardware. Se retiró.")
    vinetas(s, MARGEN, y, Inches(6.35), [
        ("Dentro: ", "reserva de plazas, pago simulado y control de acceso simulado."),
        ("Sin hardware: ", "ni lectores de matrícula, ni barreras, ni red del aparcamiento."),
        ("El control de acceso ", "se teclea en un formulario con sesión iniciada, y el sistema decide."),
        ("Las plazas ", "no se controlan físicamente: su disponibilidad se calcula."),
    ], size=17, gap=0.98)

    # panel derecho: fuera de alcance
    x = MARGEN + Inches(6.95)
    rect(s, x, y, Inches(4.5), Inches(3.55), SAND)
    texto(s, x + Inches(0.32), y + Inches(0.26), Inches(3.9), Inches(0.3),
          "FUERA DEL ALCANCE", size=10.5, color=TERRA, bold=True)
    for i, t in enumerate(["Pasarela de pago real", "Envío de correo",
                           "Aplicación móvil", "Integración con lectores"]):
        yy = y + Inches(0.78 + 0.72 * i)
        rect(s, x + Inches(0.32), yy + Inches(0.09), Inches(0.55), Pt(1.6), MUTED)
        texto(s, x + Inches(1.05), yy, Inches(3.1), Inches(0.4), t, size=15.5, color=INK_SOFT)
    pie(s, 3)
    return s



def s04_roles(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Funcionamiento", "Tres roles, tres permisos",
                 "Cada rol tiene su sesión y sus permisos se comprueban en el servidor, no en el menú.")

    tarjetas = [
        ("CLIENTE", "Entra por /login.php", INK,
         ["Se registra y da de alta su vehículo",
          "Reserva, consulta y cancelar antes de empezar",
          "Descarga el resguardo en PDF",
          "Solo mueve sus propias matrículas"]),
        ("ADMINISTRADOR", "Entra por /admin/login.php", TERRA,
         ["Ve, filtra y modifica todas las reservas",
          "Crea parkings con sus plazas y tarifas",
          "Cancela con reembolso, nunca borra",
          "Consulta la auditoría de cada acción"]),
        ("OPERADOR", "Entra por /admin/login.php", MUTED,
         ["Consulta reservas, clientes y auditoría",
          "No escribe: el 403 está en el servidor",
          "Demuestra que el permiso no es binario"]),
    ]
    ancho = Inches(3.62)
    for i, (titulo, sub, color, puntos) in enumerate(tarjetas):
        x = MARGEN + Emu(int(i * 3.95 * 914400))
        rect(s, x, y, ancho, Inches(3.05), SAND)
        rect(s, x, y, ancho, Pt(4), color)
        texto(s, x + Inches(0.28), y + Inches(0.22), ancho - Inches(0.5), Inches(0.3),
              titulo, size=12.5, color=color, bold=True)
        texto(s, x + Inches(0.28), y + Inches(0.56), ancho - Inches(0.5), Inches(0.3),
              sub, size=10, color=MUTED, italic=True)
        for j, punto in enumerate(puntos):
            yy = y + Inches(0.92 + 0.52 * j)
            rect(s, x + Inches(0.28), yy + Inches(0.09), Inches(0.06), Inches(0.06), color)
            texto(s, x + Inches(0.48), yy - Inches(0.02), ancho - Inches(0.78), Inches(0.55),
                  punto, size=10.5, color=INK_SOFT, espacio=1.05)

    texto(s, MARGEN, y + Inches(3.3), Inches(11.5), Inches(0.4),
          "La sesión de cliente y la de administrador son independientes: una no abre la otra.",
          size=11.5, color=MUTED, italic=True)
    pie(s, 4)
    return s

def s04_arquitectura(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Arquitectura", "Siete contenedores y un certificado",
                 "Todo se levanta con un comando. No hay instalación manual de PHP ni de MySQL.")

    # diagrama de bloques
    top = y + Inches(0.12)
    bw, bh = Inches(2.5), Inches(0.95)
    x0 = MARGEN
    cajas = [
        ("app", "PHP 8.2 · Apache", "HTTPS 443", INK, WHITE),
        ("db", "MariaDB 10.11", "11 tablas", INK, WHITE),
        ("phpmyadmin", "phpMyAdmin", "puerto 8080", SAND, INK),
    ]
    for i, (nom, det, extra, fondo, col) in enumerate(cajas):
        x = x0 + Emu(int(i * (bw + Inches(0.42))))
        rect(s, x, top, bw, bh, fondo)
        sub = TERRA_CLARO if fondo == INK else TERRA
        texto(s, x + Inches(0.22), top + Inches(0.14), bw - Inches(0.4), Inches(0.3), nom.upper(),
              size=9.5, color=sub, bold=True)
        texto(s, x + Inches(0.22), top + Inches(0.42), bw - Inches(0.4), Inches(0.3), det,
              size=13, color=col, bold=True)
        texto(s, x + Inches(0.22), top + Inches(0.70), bw - Inches(0.4), Inches(0.25), extra,
              size=10, color=RGBColor(0xB9, 0xC7, 0xCB) if fondo == INK else MUTED)

    # flechas de dependencia
    for i in range(2):
        x = x0 + Emu(int(i * (bw + Inches(0.42)) + bw + Inches(0.08)))
        rect(s, x, top + Inches(0.44), Inches(0.26), Pt(1.6), MUTED)

    # franja inferior con el segundo compose
    y2 = top + Inches(1.5)
    rect(s, MARGEN, y2, W - 2 * MARGEN, Inches(1.55), SAND)
    texto(s, MARGEN + Inches(0.35), y2 + Inches(0.22), Inches(4.4), Inches(0.3),
          "SEGUNDO COMPOSE · MONITORIZACIÓN", size=10, color=TERRA, bold=True)
    for i, t in enumerate(["Prometheus", "Grafana", "node-exporter", "cAdvisor"]):
        xx = MARGEN + Inches(0.35) + Emu(int(i * 2.28 * 914400))
        texto(s, xx, y2 + Inches(0.66), Inches(2.2), Inches(0.35), t, size=15, color=INK, bold=True)
        texto(s, xx, y2 + Inches(1.02), Inches(2.2), Inches(0.3),
              ["raspa /metrics.php", "paneles de negocio", "métricas del host", "métricas de contenedores"][i],
              size=9.5, color=MUTED)

    texto(s, MARGEN, y2 + Inches(1.66), W - 2 * MARGEN, Inches(0.3),
          "Servidor de demostración: VM Ubuntu 24.04.5.  ·  Copias: scripts/backup.sh, con restauración documentada.",
          size=10, color=INK_SOFT)
    pie(s, 5)
    return s


def s05_modelo(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Modelo lógico", "Once entidades, cuatro responsabilidades")
    imagen(s, IMG / "modelo-er.png", MARGEN, y - Inches(0.02), h=Inches(4.15))
    # leyenda a la derecha
    x = MARGEN + Inches(7.35)
    bloques = [
        ("CUENTAS", "CLIENTE · VEHICULO\nMETODO_PAGO", INK),
        ("OFERTA", "PARKING · PLAZA · TARIFA", INK_SOFT),
        ("OPERACIÓN", "RESERVA · PAGO\nREGISTRO_ACCESO", TERRA),
        ("GESTIÓN", "ADMINISTRADOR\nREGISTRO_AUDITORIA", MUTED),
    ]
    for i, (tit, det, col) in enumerate(bloques):
        yy = y + Inches(0.02 + 1.0 * i)
        rect(s, x, yy + Inches(0.05), Inches(0.09), Inches(0.52), col)
        texto(s, x + Inches(0.3), yy, Inches(3.6), Inches(0.28), tit, size=10, color=col, bold=True)
        texto(s, x + Inches(0.3), yy + Inches(0.3), Inches(3.6), Inches(0.62), det,
              size=11.5, color=INK_SOFT, espacio=1.12)
    texto(s, x, y + Inches(4.12), Inches(3.55), Inches(0.4),
          "Diagrama completo en docs/img/modelo-er.svg", size=9.5, color=MUTED, italic=True)
    pie(s, 6)
    return s


def s06_relaciones(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Modelo lógico", "Las quince relaciones, con su cardinalidad",
                 "No basta con enumerar tablas: hay que enseñar cómo se conectan. Estas son todas, sacadas del diagrama.")

    relaciones = [
        ("CLIENTE", "VEHICULO", "tiene", "1 : 0..*"),
        ("CLIENTE", "METODO_PAGO", "registra", "1 : 0..*"),
        ("CLIENTE", "RESERVA", "realiza", "1 : 0..*"),
        ("VEHICULO", "RESERVA", "se reserva en", "1 : 0..*"),
        ("PARKING", "PLAZA", "contiene", "1 : 0..*"),
        ("PARKING", "TARIFA", "aplica", "1 : 0..*"),
        ("PARKING", "RESERVA", "recibe", "1 : 0..*"),
        ("PLAZA", "RESERVA", "se asigna a", "0..1 : 0..*"),
        ("TARIFA", "RESERVA", "valoriza", "1 : 0..*"),
        ("RESERVA", "PAGO", "genera", "1 : 1..*"),
        ("METODO_PAGO", "PAGO", "cobra con", "1 : 0..*"),
        ("RESERVA", "REGISTRO_ACCESO", "origina", "0..1 : 0..*"),
        ("VEHICULO", "REGISTRO_ACCESO", "protagoniza", "0..1 : 0..*"),
        ("PARKING", "REGISTRO_ACCESO", "ocurre en", "1 : 0..*"),
        ("ADMINISTRADOR", "REGISTRO_AUDITORIA", "ejecuta", "0..1 : 0..*"),
    ]
    mitad = 8
    ancho_col = Inches(5.55)
    for bloque, inicio in ((0, 0), (1, mitad)):
        x = MARGEN + Emu(int(bloque * 5.95 * 914400))
        filas = [(f"{a} → {b}  ·  {c}", d) for a, b, c, d in relaciones[inicio:inicio + mitad]]
        tabla(s, x, y, ancho_col, filas, ["RELACIÓN", "CARDINALIDAD"],
              [Inches(4.6), Inches(0.95)], size=10, alto_fila=0.335)

    texto(s, MARGEN, y + Inches(3.15), Inches(11.5), Inches(0.5),
          "RESERVA concentra cinco claves foráneas y PAGO separa el cobro de la reserva del cobro del exceso.",
          size=12.5, color=MUTED, italic=True)
    pie(s, 7)
    return s


def s07_decisiones(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Modelo lógico", "Cinco decisiones que se tomaron y por qué",
                 "Cada cambio respecto al Sprint 1 tiene un motivo, no es una preferencia estética.")
    filas = [
        ("Fuera DISPOSITIVO", "No hay ningún equipo que identificar en el sistema."),
        ("Fuera barrera_abierta", "No hay barrera física que abrir ni cerrar."),
        ("PLAZA.estado", "Refleja la situación física; la disponibilidad se calcula por franja."),
        ("ADMINISTRADOR y REGISTRO_AUDITORIA", "El panel de gestión necesitaba usuarios propios y traza."),
        ("Importes congelados", "Una subida de tarifa no puede reescribir reservas antiguas."),
    ]
    yy = y
    for i, (tit, det) in enumerate(filas):
        if i % 2 == 0:
            rect(s, MARGEN - Inches(0.1), yy - Inches(0.04), W - 2 * MARGEN + Inches(0.2), Inches(0.70), SAND)
        texto(s, MARGEN + Inches(0.12), yy + Inches(0.05), Inches(4.3), Inches(0.3), tit,
              size=14, color=INK, bold=True)
        texto(s, MARGEN + Inches(4.62), yy + Inches(0.05), Inches(6.5), Inches(0.4), det,
              size=14, color=INK_SOFT)
        yy += Inches(0.70)
    pie(s, 8)
    return s


def s08_integridad(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Modelo lógico", "La garantía está en la base de datos",
                 "Si alguien intenta saltarse una regla, la rechaza MariaDB, no solo la aplicación.")

    izq = [
        ("ON DELETE RESTRICT ", "en las quince claves foráneas."),
        ("CHECK ", "de fechas, importes, precios y capacidades."),
        ("Índices ", "en las columnas que filtran las consultas del panel."),
    ]
    vinetas(s, MARGEN, y, Inches(6.3), izq, size=16, gap=0.85)

    x = MARGEN + Inches(6.75)
    # disparadores
    tarjetas = [
        ("trg_reserva_sin_borrado", "Un DELETE sobre RESERVA aborta con SQLSTATE 45000."),
        ("trg_reserva_transicion", "Impide salir del ciclo de vida por un camino inválido."),
    ]
    for i, (nom, det) in enumerate(tarjetas):
        yy = y + Inches(1.05 * i)
        rect(s, x, yy, Inches(4.75), Inches(0.9), INK)
        texto(s, x + Inches(0.26), yy + Inches(0.13), Inches(4.2), Inches(0.3), nom,
              size=11.5, color=TERRA_CLARO, bold=True)
        texto(s, x + Inches(0.26), yy + Inches(0.42), Inches(4.2), Inches(0.4), det,
              size=9.5, color=RGBColor(0xC9, 0xD4, 0xD6))
    # cadena de estados
    texto(s, MARGEN, y + Inches(2.55), Inches(6), Inches(0.3), "EL CICLO DE VIDA DE UNA RESERVA",
          size=9.5, color=TERRA, bold=True)
    estados = [
        ("PENDIENTE_PAGO", INK_SOFT),
        ("ACTIVA", INK),
        ("EN_CURSO", INK),
        ("FINALIZADA", GREEN),
        ("CON_EXCESO", GREEN),
    ]
    bw, bh = Inches(2.05), Inches(0.52)
    for i, (nom, col) in enumerate(estados):
        xx = MARGEN + Emu(int(i * (2.05 + 0.28) * 914400))
        rect(s, xx, y + Inches(2.92), bw, bh, col)
        texto(s, xx, y + Inches(3.05), bw, Inches(0.3), nom, size=9.5, color=WHITE,
              bold=True, align=PP_ALIGN.CENTER)
        if i < len(estados) - 1:
            rect(s, xx + bw + Inches(0.06), y + Inches(3.15), Inches(0.16), Pt(1.4), MUTED)
    texto(s, MARGEN, y + Inches(3.62), Inches(11.2), Inches(0.3),
          "Salidas laterales: CANCELADA y NO_PRESENTADA. Un DELETE aborta; solo se cambia de estado.",
          size=11, color=MUTED, italic=True)
    pie(s, 9)
    return s


def s09_flujo(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Funcionamiento", "El flujo de reserva, paso a paso",
                 "Si no hay plaza libre en la franja pedida, no se crea nada: ni reserva, ni pago.")
    pasos = [
        ("01", "Elige", "parking, vehículo y franja horaria"),
        ("02", "Busca", "una plaza libre compatible, dentro de una transacción"),
        ("03", "Calcula", "precio y congela los importes en la reserva"),
        ("04", "Cobra", "pago simulado y la deja en ACTIVA"),
    ]
    n = len(pasos)
    ancho = Inches(2.62)
    sep = Inches(0.30)
    for i, (num, tit, det) in enumerate(pasos):
        x = MARGEN + Emu(int(i * (2.62 + 0.30) * 914400))
        rect(s, x, y, ancho, Inches(2.5), SAND)
        texto(s, x + Inches(0.26), y + Inches(0.22), Inches(1.2), Inches(0.5), num,
              size=26, color=TERRA, bold=True)
        texto(s, x + Inches(0.26), y + Inches(0.92), ancho - Inches(0.5), Inches(0.35), tit,
              size=19, color=INK, bold=True)
        texto(s, x + Inches(0.26), y + Inches(1.38), ancho - Inches(0.5), Inches(0.9), det,
              size=12.5, color=INK_SOFT, espacio=1.15)

    rect(s, MARGEN, y + Inches(2.95), W - 2 * MARGEN, Inches(1.05), INK)
    texto(s, MARGEN + Inches(0.4), y + Inches(3.12), Inches(11.2), Inches(0.75),
          ["La plaza se elige con SELECT … FOR UPDATE: dos clientes a la vez no pueden recibir la misma plaza."],
          size=15, color=WHITE, bold=True)
    pie(s, 10)
    return s


def s10_precio(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Funcionamiento", "Precio, exceso y resguardo",
                 "El mismo cálculo se usa en la web, en el PDF y en las pruebas automáticas.")

    vinetas(s, MARGEN, y, Inches(6.15), [
        ("Horas completas o fracción, ", "mínimo una."),
        ("base = ", "horas × precio_hora"),
        ("gestión = ", "base × 10 %"),
        ("Exceso: ", "horas de retraso con 15 minutos de gracia, sin gestión."),
        ("Resguardo: ", "PDF con el código y la plaza, servido solo al dueño."),
    ], size=15, gap=0.72)

    # bloque de ejemplo
    x = MARGEN + Inches(6.6)
    rect(s, x, y - Inches(0.05), Inches(4.95), Inches(2.55), SAND)
    texto(s, x + Inches(0.4), y + Inches(0.13), Inches(4.2), Inches(0.3), "EJEMPLO · 4 HORAS",
          size=10, color=TERRA, bold=True)
    lineas = [("4 h × 1,80 €", "7,20 €"), ("Gestión 10 %", "0,72 €"), ("Total", "7,92 €")]
    for i, (a, b) in enumerate(lineas):
        yy = y + Inches(0.52 + 0.55 * i)
        ultimo = i == len(lineas) - 1
        if ultimo:
            rect(s, x + Inches(0.4), yy - Inches(0.06), Inches(4.15), Pt(1.2), SAND_DARK)
        texto(s, x + Inches(0.4), yy + Inches(0.06), Inches(2.8), Inches(0.35), a,
              size=14.5 if not ultimo else 16, color=INK_SOFT, bold=ultimo)
        texto(s, x + Inches(3.1), yy + Inches(0.06), Inches(1.45), Inches(0.35), b,
              size=14.5 if not ultimo else 16, color=INK if not ultimo else TERRA,
              bold=True, align=PP_ALIGN.RIGHT)

    texto(s, MARGEN, y + Inches(3.5), Inches(11.3), Inches(0.6),
          "22 comprobaciones automáticas cubren los casos límite: cero horas, precio negativo, porcentaje mayor que uno y el margen de 15 minutos.",
          size=12.5, color=MUTED, italic=True)
    pie(s, 11)
    return s


def s11_acceso(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Funcionamiento", "Control de acceso simulado")
    imagen(s, IMG / "captura-09-acceso-autorizado.png", MARGEN, y - Inches(0.08), h=Inches(3.5),
           pie_txt="Entrada autorizada. Todo intento queda registrado, se acepte o se deniegue.")
    x = MARGEN + Inches(6.55)
    vinetas(s, x, y + Inches(0.02), Inches(5.0), [
        ("Entrada: ", "vehículo registrado, reserva ACTIVA y dentro de la franja, con 30 minutos de margen."),
        ("Salida: ", "reserva EN_CURSO; si hay retraso, se cobra el exceso."),
        ("Denegado: ", "se guarda el motivo y se le dice al cliente a qué hora puede entrar."),
    ], size=14, gap=1.05)
    pie(s, 12)
    return s


def s12_admin(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Gestión", "El panel del administrador",
                 "No puede borrar reservas: puede modificarlas y cancelarlas, que es lo que pide el criterio.")
    w_img = Inches(5.55)
    imagen(s, IMG / "captura-17-admin-parkings.png", MARGEN, y - Inches(0.02), w=w_img,
           pie_txt="Parkings: al crear uno se generan sus plazas y su tarifa.")
    imagen(s, IMG / "captura-18-auditoria.png", MARGEN + w_img + Inches(0.28), y - Inches(0.02), w=w_img,
           pie_txt="Auditoría: las últimas acciones, con filtros y detalle.")
    pie(s, 13)
    return s


def s13_monitor(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Monitorización", "Prometheus recoge, Grafana enseña",
                 "Las métricas de negocio se leen de MariaDB en cada scrape: no son capturas preparadas.")
    w_img = Inches(5.35)
    imagen(s, IMG / "captura-14-grafana.png", MARGEN, y - Inches(0.02), w=w_img,
           pie_txt="Grafana: reservas activas, plazas libres, peticiones y errores 5xx.")
    imagen(s, IMG / "captura-15-prometheus.png", MARGEN + w_img + Inches(0.3), y - Inches(0.02), w=w_img,
           pie_txt="Prometheus: los cuatro objetivos en estado UP.")
    pie(s, 14)
    return s


def s14_verificacion(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Verificación", "Qué se ha probado, y dónde",
                 "Cada prueba se hizo en un sitio concreto y decimos cuál. Las dos suites se repitieron el 29/10 con la base recreada.")

    pruebas = [
        ("19/19", "smoke-test.sh", "dentro de la VM, con el stack completo"),
        ("53/53", "e2e_client.py", "desde el portátil contra la VM, puerto 8443"),
        ("22", "pruebas de cálculo", "horas, gestión, exceso y casos límite"),
        ("99", "consultas SQL", "marcadores con nombre y su parámetro"),
        ("96", "referencias internas", "enlaces y rutas de la documentación"),
        ("37", "archivos PHP", "sintaxis validada, cero errores"),
    ]
    for i, (cifra, tit, det) in enumerate(pruebas):
        col = i % 2
        fila = i // 2
        x = MARGEN + Emu(int(col * 5.9 * 914400))
        yy = y + Inches(fila * 1.18)
        texto(s, x, yy - Inches(0.05), Inches(1.4), Inches(0.62), cifra, size=27, color=TERRA, bold=True)
        texto(s, x + Inches(1.42), yy + Inches(0.02), Inches(4.2), Inches(0.3), tit,
              size=12.5, color=INK, bold=True)
        texto(s, x + Inches(1.42), yy + Inches(0.36), Inches(4.2), Inches(0.3), det,
              size=10.5, color=MUTED)

    rect(s, MARGEN, y + Inches(3.14), W - 2 * MARGEN, Inches(0.58), SAND)
    texto(s, MARGEN + Inches(0.35), y + Inches(3.28), Inches(11.0), Inches(0.4),
          "El pago es simulado y no se guarda ningún dato de tarjeta. Las contraseñas usan password_hash.",
          size=12.5, color=INK_SOFT)
    pie(s, 16)
    return s



def s15_planificacion(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Planificación", "Dieciséis tareas y tres responsables",
                 "Siete semanas, del 9 de octubre al 22 de noviembre. La barra dice cuándo y el color, quién.")

    semanas = ["9-15 oct", "16-22 oct", "23-29 oct", "30 oct-5 nov", "6-12 nov", "13-19 nov", "20-22 nov"]
    x0 = MARGEN + Inches(3.55)
    ancho_total = W - MARGEN - x0 - Inches(0.55)
    ancho_sem = Emu(int(ancho_total / 7))

    # cabecera de semanas
    for i, sem in enumerate(semanas):
        texto(s, x0 + Emu(int(i * ancho_total / 7)), y - Inches(0.06), ancho_sem, Inches(0.26),
              sem, size=7.5, color=MUTED, align=PP_ALIGN.CENTER)
    rect(s, x0, y + Inches(0.2), ancho_total, Pt(0.75), SAND_DARK)

    # Las mismas dieciséis tareas, con el mismo código, las mismas semanas y los
    # mismos colores que la tabla y el Gantt del apartado 6.3 del documento de
    # presentación: si cambia una lista, cambian las dos o dejan de cuadrar.
    AZUL = RGBColor(0x1A, 0x4E, 0x89)
    LILA = RGBColor(0x6C, 0x34, 0x83)
    VERDE = RGBColor(0x11, 0x78, 0x64)
    NARANJA = RGBColor(0xB9, 0x77, 0x0E)
    tareas = [
        ("4.1 Modelo relacional y DDL", "DM", 0.0, 1.0, LILA),
        ("4.2 Diseño de interfaz", "BS", 0.0, 1.0, VERDE),
        ("4.3 Servidor LAMP, Docker y SSL", "PB", 0.0, 2.0, AZUL),
        ("5.1 Autenticación, perfil y sesiones", "DM", 0.5, 1.5, LILA),
        ("5.2 Vehículos y métodos de pago", "BS", 1.0, 1.0, VERDE),
        ("6.1 Motor de precios y tarifas", "DM", 0.5, 1.5, LILA),
        ("6.2 Resguardo PDF de la reserva", "BS", 2.0, 1.0, VERDE),
        ("6.3 Condiciones y aceptación", "BS", 2.0, 1.0, VERDE),
        ("7.1 Parkings, plazas y tarifas", "PB", 1.0, 2.0, AZUL),
        ("8.1 Control de acceso por matrícula", "PB", 1.5, 1.5, AZUL),
        ("9.1 Seguridad, copias y monitorización", "PB", 2.0, 1.0, AZUL),
        ("10.1 Pruebas y corrección de defectos", "Todos", 1.0, 3.0, NARANJA),
        ("10.2 Manual técnico y de usuario", "BS", 2.0, 1.5, VERDE),
        ("10.3 Documento de presentación", "PB", 2.5, 1.5, AZUL),
        ("11.1 Presentación y vídeo", "Todos", 3.0, 1.0, NARANJA),
        ("11.2 Ensayo y entrega", "Todos", 6.0, 1.0, AZUL),
    ]
    alto = Inches(0.16)
    paso = 0.205
    for i, (nombre, quien, ini, dur, color) in enumerate(tareas):
        yy = y + Inches(0.29 + paso * i)
        texto(s, MARGEN, yy - Inches(0.035), Inches(2.85), Inches(0.2), nombre,
              size=8, color=INK_SOFT)
        texto(s, MARGEN + Inches(2.88), yy - Inches(0.035), Inches(0.62), Inches(0.2),
              quien, size=7.2, color=MUTED)
        rect(s, x0 + Emu(int(ini * ancho_total / 7)), yy, Emu(int(dur * ancho_total / 7)), alto, color)

    yy = y + Inches(3.56)
    for i, (etiqueta, color) in enumerate([("Pere Brau", AZUL), ("Dhruva Mehta", LILA),
                                          ("Boubacar Seck", VERDE), ("Todo el grupo", NARANJA)]):
        xx = MARGEN + Emu(int(i * 2.75 * 914400))
        rect(s, xx, yy + Inches(0.06), Inches(0.15), Inches(0.15), color)
        texto(s, xx + Inches(0.24), yy - Inches(0.02), Inches(2.3), Inches(0.28), etiqueta,
              size=9, color=MUTED)

    pie(s, 15)
    return s


def s17_ia(prs):
    s = slide_en_blanco(prs)
    y = cabecera(s, "Fuentes y transparencia", "Bibliografía y uso de IA",
                 "Lo que hemos consultado y lo que se ha hecho con ayuda de un agente, con qué modelo y con qué prompts.")

    texto(s, MARGEN, y, Inches(5.4), Inches(0.3), "BIBLIOGRAFÍA", size=10.5, color=TERRA, bold=True)
    fuentes = [
        ("PHP", "Manual de PDO, sesiones y password_hash"),
        ("MariaDB", "Disparadores, claves foráneas y bloqueos"),
        ("Docker", "Compose: referencia y órdenes"),
        ("Apache", "SSL/TLS y mod_rewrite"),
        ("Prometheus y Grafana", "Formato de exposición y aprovisionamiento"),
        ("OWASP", "CSRF, inyección SQL y almacenamiento de contraseñas"),
        ("ISO 32000-1", "Formato PDF, para el resguardo"),
    ]
    for j, (cab, det) in enumerate(fuentes):
        yy = y + Inches(0.45 + 0.46 * j)
        texto(s, MARGEN, yy, Inches(1.9), Inches(0.3), cab, size=10.2, color=INK, bold=True)
        texto(s, MARGEN + Inches(1.95), yy, Inches(3.5), Inches(0.3), det, size=9.8, color=INK_SOFT)
    texto(s, MARGEN, y + Inches(3.62), Inches(5.4), Inches(0.3),
          "Lista completa, con enlaces, en el apartado 8 del documento.",
          size=9, color=MUTED, italic=True)

    x = MARGEN + Inches(6.4)
    rect(s, x, y, Inches(5.05), Inches(3.55), SAND)
    texto(s, x + Inches(0.3), y + Inches(0.24), Inches(4.4), Inches(0.3),
          "DECLARACIÓN DE IA", size=10.5, color=TERRA, bold=True)
    lineas = [
        ("Agente y modelo: ", "codewhale con deepseek-v4-flash en el cierre; el desarrollo previo no dejó modelo registrado"),
        ("Qué se hizo con IA: ", "código, pruebas y documentación, revisados línea a línea"),
        ("Qué no: ", "el alcance, las decisiones de negocio y el modelo los decidió el grupo"),
        ("Prompts: ", "cinco, transcritos en el apartado 9 del documento"),
    ]
    for j, (cab, det) in enumerate(lineas):
        yy = y + Inches(0.72 + 0.70 * j)
        tb = s.shapes.add_textbox(x + Inches(0.3), yy, Inches(4.45), Inches(0.75))
        tf = tb.text_frame
        tf.word_wrap = True
        tf.margin_left = 0
        tf.margin_right = 0
        tf.margin_top = 0
        tf.margin_bottom = 0
        par = tf.paragraphs[0]
        par.line_spacing = 1.05
        r = par.add_run()
        r.text = cab
        r.font.size = Pt(10)
        r.font.bold = True
        r.font.color.rgb = INK
        r.font.name = FUENTE
        r = par.add_run()
        r.text = det
        r.font.size = Pt(10)
        r.font.color.rgb = INK_SOFT
        r.font.name = FUENTE

    pie(s, 17)
    return s

def s15_cierre(prs):
    s = slide_en_blanco(prs)
    rect(s, 0, 0, W, H, INK)
    rect(s, 0, 0, Inches(0.22), H, TERRA)
    texto(s, MARGEN, Inches(1.35), Inches(9), Inches(0.35), "CIERRE", size=11.5,
          color=TERRA_CLARO, bold=True)
    texto(s, MARGEN, Inches(1.85), Inches(11), Inches(1.0),
          "Una aplicación, no un diseño", size=44, color=WHITE, bold=True, espacio=0.98)
    rect(s, MARGEN, Inches(3.12), Inches(1.05), Pt(4), TERRA)

    puntos = [
        "Se levanta con docker compose up -d y se ha arrancado y probado de verdad.",
        "El modelo lógico está documentado con entidades, relaciones y cardinalidades.",
        "El hardware queda fuera, como pidieron los profesores.",
    ]
    for i, t in enumerate(puntos):
        yy = Inches(3.55 + 0.62 * i)
        rect(s, MARGEN, yy + Inches(0.11), Inches(0.08), Inches(0.08), TERRA)
        texto(s, MARGEN + Inches(0.32), yy, Inches(10.8), Inches(0.45), t,
              size=16, color=RGBColor(0xD3, 0xDD, 0xDF))

    texto(s, MARGEN, Inches(6.35), Inches(11), Inches(0.4),
          "Tarraco Parkings · Sprint 2 · ASIX2 0379 · Grupo 6", size=11, color=RGBColor(0x93, 0xA5, 0xA9))
    return s


def a_pdf() -> bool:
    """Convierte el PPTX a PDF con lo que haya en la máquina.

    En el aula es LibreOffice (`soffice`). En Windows puede no estar, y
    `subprocess.run(["which", ...])` ahí ni siquiera devuelve un código: lanza
    FileNotFoundError. Por eso se comprueba con shutil.which y, si no hay
    LibreOffice, se intenta PowerPoint por COM. Si no hay ninguno de los dos, se
    dice y se deja el PPTX, que ya está escrito: el PDF se puede sacar a mano.
    """
    soffice = shutil.which("soffice") or shutil.which("libreoffice")
    if soffice:
        # El perfil va a un temporal: en el exFAT del SSD LibreOffice se cuelga.
        perfil = Path(tempfile.mkdtemp(prefix="lo-profile-presentacion-"))
        try:
            subprocess.run(
                [soffice, "--headless", f"-env:UserInstallation={perfil.as_uri()}",
                 "--convert-to", "pdf", "--outdir", str(SALIDA_PDF.parent),
                 str(SALIDA_PPTX)],
                check=False, capture_output=True, timeout=300,
            )
        finally:
            shutil.rmtree(perfil, ignore_errors=True)
        return SALIDA_PDF.exists()

    if sys.platform == "win32":
        try:
            import win32com.client  # type: ignore[import-not-found]

            app = win32com.client.Dispatch("PowerPoint.Application")
            presentacion = app.Presentations.Open(str(SALIDA_PPTX), WithWindow=False)
            presentacion.SaveAs(str(SALIDA_PDF), 32)  # 32 = ppSaveAsPDF
            presentacion.Close()
            app.Quit()
            return SALIDA_PDF.exists()
        except Exception as exc:  # noqa: BLE001 - se informa y no se finge
            print(f"PDF : PowerPoint no disponible ({exc})", file=sys.stderr)
            return False

    return False


def main() -> int:
    prs = nueva_presentacion()
    for fn in (s01_portada, s02_cambio, s03_alcance, s04_roles, s04_arquitectura,
               s05_modelo, s06_relaciones, s07_decisiones, s08_integridad, s09_flujo,
               s10_precio, s11_acceso, s12_admin, s13_monitor, s15_planificacion,
               s14_verificacion, s17_ia, s15_cierre):
        fn(prs)

    SALIDA_PPTX.parent.mkdir(parents=True, exist_ok=True)
    prs.save(str(SALIDA_PPTX))
    print(f"PPTX: {SALIDA_PPTX} ({SALIDA_PPTX.stat().st_size / 1024:.0f} KB), "
          f"{len(prs.slides)} diapositivas")

    if a_pdf():
        print(f"PDF : {SALIDA_PDF} ({SALIDA_PDF.stat().st_size / 1024:.0f} KB)")
    else:
        print("PDF : no se ha generado; el PPTX está escrito", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
