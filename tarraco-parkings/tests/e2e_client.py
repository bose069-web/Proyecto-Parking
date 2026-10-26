#!/usr/bin/env python3
"""Prueba de extremo a extremo del lado cliente contra el stack real.

Se ejecuta desde el amfitrió i parla amb la VM pel port reenviat 8443. Per tant
el client és el portàtil i el servidor és la VM: dos màquines distintes, que és
el que es volia demostrar.

Recorre el flux complet d'un client: registre, login, reserva amb càlcul de
preu, consulta, entrada i eixida pel control d'accés. I després el panell
d'administració.

Ús:
    PYTHONPATH=Maquinas/_pylib python3 tarraco-parkings/tests/e2e_client.py
    ... --host https://127.0.0.1:8443
"""
from __future__ import annotations

import argparse
import re
import string
import sys
import time
from datetime import datetime, timedelta

import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

OK = 0
FALLOS = 0
letras_abc = string.ascii_lowercase


def check(nombre: str, condicion: bool, detalle: str = "") -> bool:
    global OK, FALLOS
    if condicion:
        OK += 1
        print(f"  OK    {nombre}")
    else:
        FALLOS += 1
        print(f"  FALLO {nombre}" + (f" :: {detalle}" if detalle else ""))
    return condicion


def csrf(html: str) -> str | None:
    m = re.search(r'name="_csrf" value="([^"]+)"', html)
    return m.group(1) if m else None


def alertas(html: str) -> list[tuple[str, str]]:
    """Devuelve [(tipo, texto)] de los bloques .alert de la página."""
    salida = []
    for cls, txt in re.findall(r'class="alert alert-(\w+)"[^>]*>\s*(.{0,400}?)</div>', html, re.S):
        salida.append((cls, re.sub(r"<[^>]+>", " ", txt)))
    return salida


def matricula_unica() -> str:
    """Matrícula distinta en cada ejecución (4 dígitos + 3 letras).

    Antes la prueba usaba 1234JKL, que ya viene dentro de un parking en los
    datos semilla: la segunda ejecución la encontraba EN_CURSO y la entrada se
    denegaba. Con una matrícula propia la prueba se puede repetir.
    """
    n = int(time.time()) % 10000
    letras = "".join(letras_abc[(n // (26 ** i)) % 26] for i in range(3))
    return f"{n:04d}{letras.upper()}"


def dni_unico() -> str:
    n = int(time.time()) % 100000000
    return f"{n:08d}X"


def registrar_cliente(s, base: str, email: str, clave: str) -> None:
    """Da de alta una cuenta nueva desde el formulario público."""
    r = s.get(base + "/register.php", timeout=15)
    token = csrf(r.text)
    r = s.post(base + "/register.php",
               data={"nombre": "Prueba", "apellidos": "Automática",
                     "email": email, "telefono": "600000000",
                     "documento_ident": dni_unico(),
                     "password": clave, "password2": clave,
                     "_csrf": token},
               timeout=20, allow_redirects=True)
    check("el registro crea la cuenta", "Cuenta creada" in r.text or "Entrar" in r.text)


def alta_vehiculo(s, base: str, matricula: str) -> None:
    """Añade un vehículo a la cuenta recién creada."""
    r = s.get(base + "/vehicles.php", timeout=15)
    token = csrf(r.text)
    r = s.post(base + "/vehicles.php",
               data={"accion": "crear", "matricula": matricula,
                     "tipo_vehiculo": "COCHE", "marca": "Prueba",
                     "modelo": "E2E", "color": "Gris", "_csrf": token},
               timeout=20, allow_redirects=True)
    check("el vehículo de la prueba se da de alta",
          matricula in r.text, "la matrícula no aparece en el listado")


def total_de(html: str) -> str | None:
    """Importe del bloque .grand del resumen de precio."""
    m = re.search(r'class="grand">\s*<span>Total</span>\s*<span>([\d.,]+)\s*€</span>', html)
    return m.group(1) if m else None


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="https://127.0.0.1:8443")
    ap.add_argument("--cliente", default="pere.brau@example.com")
    ap.add_argument("--clave", default="Client1234")
    ap.add_argument("--admin", default="admin@tarraco.local")
    ap.add_argument("--clave-admin", default="Admin1234")
    args = ap.parse_args()

    base = args.host.rstrip("/")
    s = requests.Session()
    s.verify = False
    s.headers.update({"User-Agent": "tarraco-e2e/1.0"})
    print(f"Sobre {base}\n")

    # ---------------------------------------------------------------- 1. público
    print("1. Páginas públicas")
    for ruta in ("/", "/login.php", "/register.php", "/admin/login.php"):
        r = s.get(base + ruta, timeout=15, allow_redirects=False)
        check(f"GET {ruta} -> 200", r.status_code == 200, f"rebut {r.status_code}")

    r = s.get(base + "/", timeout=15)
    check("la portada lista los tres parkings",
          all(p in r.text for p in ("Parc Central", "Port Esportiu", "Estació de Trens")))
    check("la portada muestra plazas libres", bool(re.search(r"\d+ plazas libres", r.text)))

    # El test de certificado: el cliente verifica que el TLS responde.
    r = requests.get(base + "/", verify=False, timeout=15)
    check("TLS responde y sirve HTML", "text/html" in r.headers.get("Content-Type", ""))

    # --------------------------------------------------------------- 2. cuenta
    print("\n2. Cuenta nueva y sesión de cliente")

    # La cuenta y el vehículo son propios de esta ejecución: así la prueba no
    # depende de los datos semilla ni de lo que hayan dejado otras pasadas.
    email = f"e2e.{int(time.time())}@example.com"
    clave = "Clave1234"
    matricula = matricula_unica()
    print(f"    cuenta {email} · matrícula {matricula}")

    registrar_cliente(s, base, email, clave)
    r = s.get(base + "/login.php", timeout=15)
    token = csrf(r.text)
    check("el formulario trae token CSRF", token is not None)

    r = s.post(base + "/login.php",
               data={"email": args.cliente, "password": "clave-incorrecta", "_csrf": token},
               timeout=15, allow_redirects=True)
    check("una contraseña incorrecta no abre sesión",
          "no son correctos" in r.text or "Entrar" in r.text)

    r = s.get(base + "/login.php", timeout=15)
    token = csrf(r.text)
    r = s.post(base + "/login.php",
               data={"email": email, "password": clave, "_csrf": token},
               timeout=15, allow_redirects=False)
    check("login correcto redirige (303)", r.status_code == 303, f"rebut {r.status_code}")

    r = s.get(base + "/dashboard.php", timeout=15)
    check("el panel del cliente carga", r.status_code == 200 and "Hola," in r.text)

    alta_vehiculo(s, base, matricula)

    # Control de acceso: exige sesión (redirige al login si no la hay).
    anon = requests.Session(); anon.verify = False
    r = anon.get(base + "/access-control.php", timeout=15, allow_redirects=False)
    check("el control de acceso exige sesión", r.status_code == 303, f"rebut {r.status_code}")

    # -------------------------------------------------------------- 3. reserva
    print("\n3. Reserva con cálculo de precio")
    r = s.get(base + "/reservation.php", timeout=15)
    token = csrf(r.text)
    check("el formulario de reserva carga", r.status_code == 200 and token is not None)

    # El identificador del vehículo sale del propio formulario.
    m = re.search(r'name="id_vehiculo"[^>]*>.*?value="(\d+)"[^>]*>\s*' + re.escape(matricula),
                  r.text, re.S)
    id_vehiculo = m.group(1) if m else None
    check("el vehículo nuevo aparece en el formulario de reserva", id_vehiculo is not None)

    r = s.post(base + "/reservation.php",
               data={"id_parking": 1, "id_vehiculo": id_vehiculo,
                     "entrada": "2020-01-01T10:00", "salida": "2020-01-01T12:00",
                     "_csrf": token},
               timeout=15)
    check("una franja pasada se rechaza",
          "ya ha empezado" in r.text or "no es válida" in r.text.lower())

    entrada = (datetime.now() + timedelta(minutes=10)).strftime("%Y-%m-%dT%H:%M")
    salida = (datetime.now() + timedelta(hours=4)).strftime("%Y-%m-%dT%H:%M")

    r = s.post(base + "/reservation.php",
               data={"id_parking": 1, "id_vehiculo": id_vehiculo,
                     "entrada": entrada, "salida": salida,
                     "observaciones": "Reserva de la prueba e2e", "_csrf": token},
               timeout=20)
    check("la reserva se crea", r.status_code == 200, f"rebut {r.status_code}")

    m = re.search(r"(TR-\d{4}-[0-9A-F]{8})", r.text)
    codigo = m.group(1) if m else None
    check("la reserva tiene código", codigo is not None, r.text[:200])

    total = total_de(r.text)
    check("el detalle muestra el total", total is not None)

    if codigo and total:
        # 4 h * 1,80 = 7,20 base + 0,72 gestión = 7,92
        limpio = total.replace(".", "").replace(",", "")
        check("el precio cuadra con la tarifa (7,92 €)", limpio == "792", f"rebut {total}")

    r = s.get(base + "/my-reservations.php", timeout=15)
    check("la reserva aparece en el historial", codigo is not None and codigo in r.text)

    # Identificador interno para comprobar después el aislamiento entre clientes.
    m = re.search(r"/reservation\.php\?id=(\d+)", r.text)
    id_reserva = m.group(1) if m else None

    # ------------------------------------------------------- 4. acceso entrada
    print("\n4. Control de acceso (entrada y salida)")
    r = s.get(base + "/access-control.php", timeout=15)
    token = csrf(r.text)

    r = s.post(base + "/access-control.php",
               data={"id_parking": 1, "matricula": matricula, "movimiento": "ENTRADA",
                     "_csrf": token},
               timeout=15)
    al = alertas(r.text)
    check("la entrada se autoriza",
          any(t == "success" and "autorizado" in x.lower() for t, x in al),
          f"alertas={[(t, x.strip()[:60]) for t, x in al]}")

    r = s.get(base + "/dashboard.php", timeout=15)
    check("el panel refleja la reserva en curso", "EN_CURSO" in r.text or "en curso" in r.text)

    r = s.get(base + "/access-control.php", timeout=15)
    token = csrf(r.text)
    r = s.post(base + "/access-control.php",
               data={"id_parking": 1, "matricula": "9999ZZZ", "movimiento": "ENTRADA",
                     "_csrf": token},
               timeout=15)
    al = alertas(r.text)
    check("una matrícula desconocida se deniega",
          any(t == "danger" and "denegado" in x.lower() for t, x in al),
          f"alertas={[(t, x.strip()[:60]) for t, x in al]}")
    check("la denegación explica el motivo",
          any("no está registrada" in x.lower() for _, x in al))

    r = s.get(base + "/access-control.php", timeout=15)
    token = csrf(r.text)
    r = s.post(base + "/access-control.php",
               data={"id_parking": 1, "matricula": matricula, "movimiento": "SALIDA",
                     "_csrf": token},
               timeout=15)
    al = alertas(r.text)
    # El mensaje del modelo dice solo cómo ha ido la salida («Dentro del tiempo
    # previsto» o «Exceso de N h: importe»); el encabezado lo pone la vista.
    check("la salida se registra",
          any(t == "success" and ("dentro del tiempo" in x.lower() or "exceso" in x.lower())
              for t, x in al),
          f"alertas={[(t, x.strip()[:60]) for t, x in al]}")

    # -------------------------------------------------------- 5. otro cliente
    print("\n5. Aislamiento entre clientes")
    otro = requests.Session(); otro.verify = False
    r = otro.get(base + "/login.php", timeout=15)
    t2 = csrf(r.text)
    r = otro.post(base + "/login.php",
                  data={"email": "dhruva.mehta@example.com", "password": "Client1234", "_csrf": t2},
                  timeout=15, allow_redirects=False)
    check("un segundo cliente entra", r.status_code == 303)

    if codigo:
        r = otro.get(base + "/my-reservations.php", timeout=15)
        check("el segundo cliente no ve la reserva del primero",
              codigo not in r.text)

    if id_reserva:
        r = otro.get(f"{base}/reservation.php?id={id_reserva}", timeout=15)
        check("el segundo cliente no puede abrir el detalle de la reserva ajena",
              "No existe ninguna reserva tuya" in r.text)

    # ------------------------------------------------------- 6. administración
    print("\n6. Panel de administración")
    adm = requests.Session(); adm.verify = False
    r = adm.get(base + "/admin/login.php", timeout=15)
    t3 = csrf(r.text)
    r = adm.post(base + "/admin/login.php",
                 data={"email": args.admin, "password": args.clave_admin, "_csrf": t3},
                 timeout=15, allow_redirects=False)
    check("el administrador entra", r.status_code == 303, f"rebut {r.status_code}")

    r = adm.get(base + "/admin/index.php", timeout=15)
    check("el panel de administración carga", r.status_code == 200 and "Resumen general" in r.text)
    check("el panel muestra ingresos", "Ingresos" in r.text)

    r = adm.get(base + "/admin/reservations.php", timeout=15)
    check("el listado de reservas carga", r.status_code == 200)
    check("la reserva de la prueba aparece al administrador",
          codigo is not None and codigo in r.text)

    r = adm.get(base + "/admin/users.php", timeout=15)
    check("el listado de clientes carga", r.status_code == 200 and "Clientes" in r.text)

    # La traza de auditoría se escribe desde diez sitios y hasta el 23/10/2026
    # no había forma de leerla desde la aplicación.
    r = adm.get(base + "/admin/audit.php", timeout=15)
    check("la auditoría carga y muestra acciones",
          r.status_code == 200 and "Auditoría" in r.text and "<tr>" in r.text)

    # Un cliente no puede entrar al panel de administración.
    r = s.get(base + "/admin/index.php", timeout=15, allow_redirects=False)
    check("un cliente no accede al panel de administración", r.status_code == 303)

    # ------------------------------------------------------------ 7. métricas
    # ------------------------------------------- 7. alta de parking completo
    print("\n7. Alta de un parking desde el panel")
    # El 23/10/2026 un parking creado desde el panel nacía con capacidad pero
    # sin plazas ni tarifa: aparecía en el desplegable con «0 libres» y no se
    # podía reservar en él. Esta sección comprueba que el ciclo se cierra.
    nombre = "Parking E2E " + str(int(time.time()) % 100000)
    r = adm.get(base + "/admin/parkings.php", timeout=15)
    t4 = csrf(r.text)
    r = adm.post(base + "/admin/parkings.php",
                 data={"accion": "crear", "nombre": nombre,
                       "direccion": "Calle de la Prueba, 1", "ciudad": "Tarragona",
                       "capacidad_total": "3", "precio_hora": "2.25",
                       "horario": "24 h", "activo": "1", "_csrf": t4},
                 timeout=20, allow_redirects=True)
    check("el parking se crea", "Parking creado" in r.text, r.text[:200])

    # El alta tiene que quedar en la traza: es lo que hace útil la auditoría.
    r = adm.get(base + "/admin/audit.php?tabla=PARKING", timeout=15)
    check("el alta del parking queda auditada",
          r.status_code == 200 and "PARKING" in r.text and "CREAR" in r.text)

    # Debe salir en el desplegable del cliente con sus tres plazas libres.
    r = s.get(base + "/reservation.php", timeout=15)
    check("el parking nuevo aparece con sus plazas libres",
          f"{nombre} — 3 libres" in r.text,
          "no aparece o no cuenta las plazas")

    # Y el precio tiene que salir de la tarifa creada con el parking.
    m = re.search(r'name="id_parking"[^>]*>(.*?)</select>', r.text, re.S)
    id_nuevo = None
    if m:
        op = re.search(r'value="(\d+)"[^>]*>\s*' + re.escape(nombre), m.group(1), re.S)
        id_nuevo = op.group(1) if op else None
    check("el parking nuevo tiene identificador", id_nuevo is not None)

    if id_nuevo:
        entrada2 = (datetime.now() + timedelta(hours=2)).strftime("%Y-%m-%dT%H:%M")
        salida2 = (datetime.now() + timedelta(hours=4)).strftime("%Y-%m-%dT%H:%M")
        r = s.get(f"{base}/quote.php?id_parking={id_nuevo}&id_vehiculo={id_vehiculo}"
                  f"&entrada={entrada2}&salida={salida2}", timeout=15)
        datos = r.json() if r.headers.get("Content-Type", "").startswith("application/json") else {}
        check("el parking nuevo cotiza con su tarifa",
              datos.get("ok") is True and datos.get("precio_hora", "").startswith("2,25"),
              str(datos)[:160])

    # ------------------------------------------------ 8. baja de una plaza
    print("\n8. Retirada de una plaza")
    if id_nuevo:
        r = adm.get(f"{base}/admin/parkings.php?edit={id_nuevo}", timeout=15)
        t5 = csrf(r.text)
        m = re.search(r'name="id_plaza" value="(\d+)"', r.text)
        id_plaza = m.group(1) if m else None
        check("la página del parking lista sus plazas", id_plaza is not None)

        if id_plaza:
            r = adm.post(base + "/admin/parkings.php",
                         data={"accion": "estado_plaza", "id_plaza": id_plaza,
                               "estado": "FUERA_SERVICIO", "volver": id_nuevo,
                               "_csrf": t5},
                         timeout=20, allow_redirects=True)
            check("la plaza queda fuera de servicio",
                  "fuera de servicio" in r.text.lower(), r.text[:160])

            r = s.get(base + "/reservation.php", timeout=15)
            check("la disponibilidad baja a dos plazas",
                  f"{nombre} — 2 libres" in r.text,
                  "la plaza retirada se sigue ofreciendo")

    print("\n9. Métricas")
    r = requests.get(base + "/metrics.php", verify=False, timeout=15)
    check("metrics.php responde", r.status_code == 200)
    check("expone reservas activas", "tarraco_reservas_activas" in r.text)
    check("expone contadores HTTP", "tarraco_http_requests_total" in r.text)
    check("expone ingresos", "tarraco_ingresos_confirmados" in r.text)

    print(f"\nRESULTADO: {OK}/{OK + FALLOS} comprobaciones correctas.")
    return 0 if FALLOS == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
