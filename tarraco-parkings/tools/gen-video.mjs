/**
 * gen-video.mjs — graba la demo del Sprint 2 contra la aplicación en marcha.
 *
 *   node tools/gen-video.mjs [--host https://127.0.0.1:8443]
 *
 * Graba la pestaña con el screencast de Chrome DevTools (no capturas sueltas),
 * escribe los fotogramas en un directorio temporal y monta el vídeo con ffmpeg:
 * tarjeta de entrada, rótulos por capítulo y tarjeta de cierre.
 *
 * No inventa nada: todo lo que se ve son páginas reales de la aplicación.
 */
import { chromium } from '/media/usuari-tarda/Extreme Pro/Pere Brau/ASIX 2/Maquinas/_npm/node_modules/playwright-core/index.mjs';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const arg = (n, d) => {
  const i = args.indexOf(`--${n}`);
  return i >= 0 ? args[i + 1] : d;
};

const HOST = arg('host', 'https://127.0.0.1:8443');
const RAIZ = path.resolve(decodeURIComponent(path.dirname(new URL(import.meta.url).pathname)), '..');
const DOCS = path.join(RAIZ, 'docs');
const TMP = path.join('/tmp', 'tarraco-video');
const SALIDA = path.join(DOCS, 'VIDEO-DEMO.mp4');

const CLIENTE = { email: 'pere.brau@example.com', clave: 'Client1234' };
const ADMIN = { email: 'admin@tarraco.local', clave: 'Admin1234' };
const MATRICULA_NUEVA = '5678MNP';   // segundo vehículo de pere.brau
const MATRICULA_AJENA = '9999ZZZ';   // no existe en la base
const MATRICULA_EXCESO = '1234JKL';  // reserva EN_CURSO con salida prevista vencida

const DURACION_TARJETA = 6;
const DURACION_CIERRE = 9;

fs.rmSync(TMP, { recursive: true, force: true });
fs.mkdirSync(TMP, { recursive: true });

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function fechaLocal(horas) {
  const d = new Date(Date.now() + horas * 3600 * 1000);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

const capitulos = [];   // { t, texto }
let t0 = 0;

function rotulo(texto) {
  const t = (Date.now() - t0) / 1000;
  capitulos.push({ t, texto });
  console.log(`  [${t.toFixed(1).padStart(6)}s] ${texto}`);
}

// ------------------------------------------------------------------ grabación
const navegador = await chromium.launch({
  executablePath: '/usr/bin/google-chrome',
  headless: true,
  args: ['--no-sandbox', '--hide-scrollbars', '--force-device-scale-factor=1',
         '--disable-lcd-text', '--font-render-hinting=none'],
});
const contexto = await navegador.newContext({
  viewport: { width: 1280, height: 800 },
  ignoreHTTPSErrors: true,
  locale: 'es-ES',
  timezoneId: 'Europe/Madrid',
});
const pagina = await contexto.newPage();

const cdp = await contexto.newCDPSession(pagina);
let nFotograma = 0;
const fotogramas = [];   // { archivo, t }
let grabando = false;
let inicio = 0;

cdp.on('Page.screencastFrame', async (ev) => {
  if (!grabando) {
    await cdp.send('Page.screencastFrameAck', { sessionId: ev.sessionId }).catch(() => {});
    return;
  }
  const t = (Date.now() - inicio) / 1000;
  const archivo = `f${String(nFotograma++).padStart(6, '0')}.jpg`;
  fs.writeFileSync(path.join(TMP, archivo), Buffer.from(ev.data, 'base64'));
  fotogramas.push({ archivo, t });
  await cdp.send('Page.screencastFrameAck', { sessionId: ev.sessionId }).catch(() => {});
});

await cdp.send('Page.enable');
await cdp.send('Page.startScreencast', {
  format: 'jpeg', quality: 82, maxWidth: 1280, maxHeight: 800, everyNthFrame: 1,
});

// ------------------------------------------------------------------ tarjeta
const TARJETA = `data:text/html;charset=utf-8,${encodeURIComponent(`
<html><body style="margin:0;height:100vh;background:#1B2A32;color:#fff;
 font-family:Calibri,Carlito,sans-serif;display:flex;flex-direction:column;justify-content:center;
 padding:0 90px;box-sizing:border-box;">
 <div style="width:56px;height:4px;background:#C4613C;margin-bottom:34px"></div>
 <div style="font-size:13px;letter-spacing:.28em;color:#E8A98C;font-weight:700">ASIX2 · 0379 · GRUPO 6</div>
 <div style="font-size:58px;font-weight:700;line-height:1.05;margin-top:18px">Tarraco Parkings</div>
 <div style="font-size:34px;color:#C9D4D6;margin-top:4px">Demostración del Sprint 2</div>
 <div style="font-size:17px;color:#93A5A9;margin-top:40px">
   Reserva, precio, control de acceso, administración y monitorización</div>
</body></html>`)}`;

async function ir(url) {
  await pagina.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await pagina.waitForTimeout(350);
}

async function escribir(sel, texto) {
  await pagina.fill(sel, '');
  await pagina.type(sel, texto, { delay: 55 });
  await dormir(250);
}

console.log('Grabando…');
// La portada se carga antes de abrir el grifo: así el vídeo no empieza en blanco.
await ir(`${HOST}/`);
inicio = Date.now();
t0 = inicio;
grabando = true;
nFotograma = 1;
// El screencast solo emite cuando la página cambia. Como la portada se queda
// quieta, sin este fotograma el vídeo empezaría once segundos más tarde.
fs.writeFileSync(path.join(TMP, 'f000000.jpg'),
  Buffer.from(await pagina.screenshot({ type: 'jpeg', quality: 82 })));
fotogramas.push({ archivo: 'f000000.jpg', t: 0 });

// 1 · portada pública
rotulo('La portada pública: los tres aparcamientos y sus plazas libres');
await dormir(11000);

// 2 · login
rotulo('Acceso como cliente');
await pagina.click('a[href$="login.php"]').catch(async () => { await ir(`${HOST}/login.php`); });
await dormir(1500);
await ir(`${HOST}/login.php`);
await escribir('#email', CLIENTE.email);
await dormir(600);
await escribir('#password', CLIENTE.clave);
await dormir(700);
await pagina.click('button[type="submit"]');
await pagina.waitForLoadState('domcontentloaded');
await dormir(6000);

// 3 · panel
rotulo('El panel del cliente: reservas activas y vehículos');
await dormir(12000);

// 4 · nueva reserva
rotulo('Nueva reserva: parking, vehículo y franja horaria');
await ir(`${HOST}/reservation.php`);
await dormir(2500);
const opciones = await pagina.$$eval('#id_parking option', (os) =>
  os.map((o) => ({ value: o.value, text: o.textContent.trim() })));
const parking = opciones.find((o) => o.text.includes('Parc Central')) || opciones[0];
await pagina.selectOption('#id_parking', parking.value);
await dormir(1200);
await pagina.selectOption('#id_vehiculo', { label: MATRICULA_NUEVA }).catch(async () => {
  const vs = await pagina.$$eval('#id_vehiculo option', (os) =>
    os.map((o) => ({ value: o.value, text: o.textContent.trim() })));
  const v = vs.find((o) => o.text.includes(MATRICULA_NUEVA)) || vs[0];
  await pagina.selectOption('#id_vehiculo', v.value);
});
await dormir(900);
await pagina.fill('#entrada', '');
await pagina.fill('#entrada', fechaLocal(0));
await dormir(900);
await pagina.fill('#salida', '');
await pagina.fill('#salida', fechaLocal(4));
await dormir(2800);
rotulo('El precio se calcula en vivo: horas, gestión del 10 % y total');
await dormir(6000);
rotulo('Las condiciones hay que aceptarlas: sin la casilla no se crea la reserva');
await pagina.locator('input[name="condiciones"]').scrollIntoViewIfNeeded();
await dormir(1200);
await pagina.check('input[name="condiciones"]');
await dormir(5000);
await pagina.click('button:has-text("Confirmar y pagar")');
await pagina.waitForLoadState('domcontentloaded');
await dormir(1500);
rotulo('Reserva confirmada: ACTIVA, con plaza asignada y su resguardo PDF');
await dormir(6500);

// 5 · mis reservas
rotulo('Mis reservas: la nueva queda ACTIVA y el resguardo se descarga en PDF');
await ir(`${HOST}/my-reservations.php`);
await dormir(9000);

// 6 · control de acceso, entrada autorizada
rotulo('Control de acceso simulado: entrada autorizada');
await ir(`${HOST}/access-control.php`);
await dormir(1800);
await pagina.selectOption('#id_parking', parking.value).catch(() => {});
await dormir(800);
await escribir('#matricula', MATRICULA_NUEVA);
await dormir(1200);
await pagina.click('button[name="movimiento"][value="ENTRADA"], button:has-text("Registrar entrada")');
await pagina.waitForLoadState('domcontentloaded');
await dormir(9000);

// 7 · entrada denegada
rotulo('Una matrícula sin reserva: el sistema la deniega y explica por qué');
await ir(`${HOST}/access-control.php`);
await dormir(1500);
await pagina.selectOption('#id_parking', parking.value).catch(() => {});
await dormir(700);
await escribir('#matricula', MATRICULA_AJENA);
await dormir(1000);
await pagina.click('button[name="movimiento"][value="ENTRADA"], button:has-text("Registrar entrada")');
await pagina.waitForLoadState('domcontentloaded');
await dormir(8000);

// 8 · salida con exceso
rotulo('Salida con retraso: se cobra el exceso por horas completas');
await ir(`${HOST}/access-control.php`);
await dormir(1500);
await pagina.selectOption('#id_parking', parking.value).catch(() => {});
await dormir(700);
await escribir('#matricula', MATRICULA_EXCESO);
await dormir(1000);
await pagina.click('button[name="movimiento"][value="SALIDA"], button:has-text("Registrar salida")');
await pagina.waitForLoadState('domcontentloaded');
await dormir(9000);

// 9 · mis reservas con el exceso
rotulo('La reserva queda CON_EXCESO y el importe aparece en el listado');
await ir(`${HOST}/my-reservations.php`);
await dormir(8000);

// 10 · perfil
rotulo('El perfil guarda el método de pago con el que se cobra');
await ir(`${HOST}/profile.php`);
await dormir(7000);

// 11 · administración
rotulo('Acceso al panel de administración');
await ir(`${HOST}/logout.php`);
await dormir(1500);
await ir(`${HOST}/admin/login.php`);
await escribir('#email', ADMIN.email);
await dormir(600);
await escribir('#password', ADMIN.clave);
await dormir(600);
await pagina.click('button[type="submit"]');
await pagina.waitForLoadState('domcontentloaded');
await dormir(9000);

// 12 · reservas
rotulo('Reservas: filtrar, editar fechas y cancelar sin borrar');
await ir(`${HOST}/admin/reservations.php`);
await dormir(9000);
await pagina.selectOption('select[name="estado"]', { label: 'CON_EXCESO' }).catch(() => {});
await dormir(900);
await pagina.click('button:has-text("Filtrar")').catch(() => {});
await pagina.waitForLoadState('domcontentloaded').catch(() => {});
await dormir(7000);

// 13 · parkings
rotulo('Parkings: al crear uno se generan sus plazas y su tarifa');
await ir(`${HOST}/admin/parkings.php`);
await dormir(9000);
await pagina.locator('a[href*="edit="]').first().click().catch(() => {});
await pagina.waitForLoadState('domcontentloaded').catch(() => {});
await dormir(6000);

// 14 · auditoría
rotulo('Auditoría: las últimas acciones, con filtros y detalle');
await ir(`${HOST}/admin/audit.php`);
await dormir(9000);
await pagina.selectOption('select[name="tabla"]', { label: 'RESERVA' }).catch(() => {});
await dormir(800);
await pagina.click('button:has-text("Filtrar")').catch(() => {});
await dormir(4000);

// 15 · métricas
rotulo('Métricas en formato Prometheus, en /metrics.php');
await ir(`${HOST}/metrics.php`);
await dormir(8000);

// 16 · Prometheus
rotulo('Prometheus: los cuatro objetivos en estado UP');
await ir('http://127.0.0.1:9090/targets');
await dormir(9000);

// 17 · Grafana
rotulo('Grafana: reservas activas, ingresos, peticiones y errores');
await ir('http://127.0.0.1:3000/login');
await escribir('input[name="user"]', 'admin');
await dormir(500);
await escribir('input[name="password"]', 'tarraco_grafana_2026');
await dormir(500);
await pagina.click('button[type="submit"]');
await pagina.waitForLoadState('domcontentloaded').catch(() => {});
await dormir(2500);
await ir('http://127.0.0.1:3000/d/tarraco-sprint2/tarraco-parkings-c2b7-sprint-2?kiosk');
await dormir(4000);
await pagina.evaluate(() => {
  const b = [...document.querySelectorAll('button')].find((x) => /kiosk/i.test(x.textContent || ''));
  if (b) b.click();
}).catch(() => {});
await dormir(18000);
await pagina.mouse.move(620, 380);
await dormir(5000);

// 18 · phpMyAdmin
rotulo('phpMyAdmin: las once tablas del modelo');
await ir('http://127.0.0.1:8081/index.php');
await dormir(1200);
await escribir('input[name="pma_username"]', 'tarraco');
await dormir(500);
await escribir('input[name="pma_password"]', 'tarraco_dev_2026');
await dormir(600);
await pagina.press('input[name="pma_password"]', 'Enter');
await pagina.waitForLoadState('domcontentloaded').catch(() => {});
// La sesión de phpMyAdmin tarda en quedar lista: si se navega antes, la ruta de
// la base de datos redirige a la página del servidor.
for (let intento = 0; intento < 3; intento++) {
  await dormir(2500);
  await ir('http://127.0.0.1:8081/index.php?route=/database/structure&db=tarraco_parkings');
  const cuerpo = await pagina.textContent('body').catch(() => '');
  if (/RESERVA/.test(cuerpo)) break;
}
await dormir(9000);

// cierre
grabando = false;
const duracionGrabacion = (Date.now() - inicio) / 1000;
console.log(`Grabación: ${duracionGrabacion.toFixed(1)} s, ${fotogramas.length} fotogramas`);

// Los instantes reales se guardan: reconstruirlos después desde el mtime de los
// ficheros desplaza los rótulos varios segundos.
fs.writeFileSync(path.join(TMP, 'capitulos.json'), JSON.stringify({
  inicioMs: inicio,
  duracion: duracionGrabacion,
  capitulos,
  fotogramas,
}, null, 1));

await contexto.close();
await navegador.close();

// ------------------------------------------------------------------ tarjetas
// La tarjeta de entrada y la de cierre se dibujan en el mismo Chrome, así que
// comparten tipografía y color con el resto del vídeo.
async function dibujarTarjetas() {
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome', headless: true,
    args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1280, height: 800 } });
  await p.goto(TARJETA);
  await p.screenshot({ path: path.join(TMP, 'tarjeta.png') });
  await p.setContent(`<html><body style="margin:0;height:100vh;background:#1B2A32;color:#fff;
    font-family:Calibri,Carlito,sans-serif;display:flex;flex-direction:column;justify-content:center;
    padding:0 90px;box-sizing:border-box;">
    <div style="width:56px;height:4px;background:#C4613C;margin-bottom:34px"></div>
    <div style="font-size:13px;letter-spacing:.28em;color:#E8A98C;font-weight:700">GRACIAS</div>
    <div style="font-size:52px;font-weight:700;line-height:1.08;margin-top:16px">
      Una aplicación, no un diseño</div>
    <div style="font-size:18px;color:#C9D4D6;margin-top:26px;line-height:1.6">
      docker compose up -d · 11 tablas · 15 relaciones<br>
      smoke-test 18/18 · e2e 50/50</div>
    <div style="font-size:15px;color:#93A5A9;margin-top:46px">
      Tarraco Parkings · Sprint 2 · ASIX2 0379 · Grupo 6</div>
    </body></html>`);
  await p.screenshot({ path: path.join(TMP, 'cierre.png') });
  await b.close();
}

await dibujarTarjetas();

// ------------------------------------------------------------------ montaje
// El montaje vive en montar-video.mjs: usa los instantes reales que se acaban
// de guardar y no recorta los huecos entre fotogramas.
execFileSync(process.execPath, [path.join(RAIZ, 'tools', 'montar-video.mjs'),
  '--dir', TMP, '--salida', SALIDA], { stdio: 'inherit' });

