/**
 * montar-video.mjs — monta el vídeo a partir de los fotogramas ya grabados.
 *
 *   node tools/montar-video.mjs [--dir /tmp/tarraco-video] [--salida docs/VIDEO-DEMO.mp4]
 *
 * Existe porque el screencast solo emite fotograma cuando la página cambia: la
 * duración de cada fotograma es el hueco hasta el siguiente, sin recortes. Si se
 * recorta, el vídeo se acelera y los rótulos dejan de caer donde toca.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const arg = (n, d) => {
  const i = args.indexOf(`--${n}`);
  return i >= 0 ? args[i + 1] : d;
};

const TMP = arg('dir', '/tmp/tarraco-video');
const RAIZ = path.resolve(decodeURIComponent(path.dirname(new URL(import.meta.url).pathname)), '..');
const SALIDA = path.resolve(arg('salida', path.join(RAIZ, 'docs', 'VIDEO-DEMO.mp4')));

const meta = JSON.parse(fs.readFileSync(path.join(TMP, 'capitulos.json'), 'utf8'));
const capitulos = meta.capitulos;
const duracion = meta.duracion;

// Si el grabador dejó los instantes reales, se usan tal cual. Reconstruirlos
// desde el mtime desplaza los rótulos: el primer fotograma no llega en el
// instante en que se abre el grifo.
let fotogramas;
if (Array.isArray(meta.fotogramas) && meta.fotogramas.length) {
  fotogramas = meta.fotogramas.filter((f) => fs.existsSync(path.join(TMP, f.archivo)));
} else {
  fotogramas = fs.readdirSync(TMP)
    .filter((f) => /^f\d+\.jpg$/.test(f))
    .sort()
    .map((f) => ({ archivo: f, t: 0 }));
  for (const f of fotogramas) {
    f.t = (fs.statSync(path.join(TMP, f.archivo)).mtimeMs - meta.inicioMs) / 1000;
  }
}
fotogramas.sort((a, b) => a.t - b.t);

// lista de concatenación con la duración real de cada fotograma
const lista = [];
for (let i = 0; i < fotogramas.length; i++) {
  const t = fotogramas[i].t;
  const sig = i + 1 < fotogramas.length ? fotogramas[i + 1].t : duracion;
  const dur = Math.max(sig - t, 1 / 30);
  lista.push(`file '${path.join(TMP, fotogramas[i].archivo)}'`);
  lista.push(`duration ${dur.toFixed(4)}`);
}
lista.push(`file '${path.join(TMP, fotogramas[fotogramas.length - 1].archivo)}'`);
fs.writeFileSync(path.join(TMP, 'lista.txt'), lista.join('\n'));

const DUR_TARJETA = 6;
const DUR_CIERRE = 9;

function tiempoAss(s) {
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = (s % 60).toFixed(2).padStart(5, '0');
  return `${h}:${String(m).padStart(2, '0')}:${sec}`;
}

const corte = capitulos.map((c, i) => ({
  ini: c.t + DUR_TARJETA,
  fin: (i + 1 < capitulos.length ? capitulos[i + 1].t : duracion) + DUR_TARJETA,
  texto: c.texto,
}));

const ass = [
  '[Script Info]',
  'ScriptType: v4.00+',
  'PlayResX: 1280',
  'PlayResY: 800',
  'WrapStyle: 2',
  '',
  '[V4+ Styles]',
  'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
  'Style: Rotulo,Carlito,27,&H00FFFFFF,&H00FFFFFF,&H00201A10,&HB4201A10,1,0,0,0,100,100,0,0,3,11,0,2,60,60,64,1',
  '',
  '[Events]',
  'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
  ...corte.map((c) => `Dialogue: 0,${tiempoAss(c.ini)},${tiempoAss(c.fin)},Rotulo,,0,0,0,,${c.texto}`),
].join('\n');
fs.writeFileSync(path.join(TMP, 'rotulos.ass'), ass);

const vA = path.join(TMP, 'a.mp4');
const vB = path.join(TMP, 'b.mp4');
const vC = path.join(TMP, 'c.mp4');
const tarjeta = path.join(TMP, 'tarjeta.png');
const cierre = path.join(TMP, 'cierre.png');

if (!fs.existsSync(tarjeta) || !fs.existsSync(cierre)) {
  throw new Error('faltan tarjeta.png o cierre.png');
}

const comun = ['-y', '-hide_banner', '-loglevel', 'error'];

console.log(`Montando ${fotogramas.length} fotogramas, ${duracion.toFixed(1)} s…`);
execFileSync('ffmpeg', [...comun, '-f', 'concat', '-safe', '0', '-i', path.join(TMP, 'lista.txt'),
  '-fps_mode', 'vfr', '-vf', 'fps=15,scale=1280:800:flags=lanczos,format=yuv420p',
  '-c:v', 'libx264', '-preset', 'medium', '-crf', '22', vA]);

fs.writeFileSync(path.join(TMP, 'tarjeta.txt'),
  `file '${tarjeta}'\nduration ${DUR_TARJETA}\nfile '${tarjeta}'\n`);
execFileSync('ffmpeg', [...comun, '-f', 'concat', '-safe', '0', '-i', path.join(TMP, 'tarjeta.txt'),
  '-fps_mode', 'vfr', '-vf', 'fps=15,scale=1280:800,format=yuv420p',
  '-c:v', 'libx264', '-preset', 'medium', '-crf', '22', vB]);

fs.writeFileSync(path.join(TMP, 'cierre.txt'),
  `file '${cierre}'\nduration ${DUR_CIERRE}\nfile '${cierre}'\n`);
execFileSync('ffmpeg', [...comun, '-f', 'concat', '-safe', '0', '-i', path.join(TMP, 'cierre.txt'),
  '-fps_mode', 'vfr', '-vf', 'fps=15,scale=1280:800,format=yuv420p',
  '-c:v', 'libx264', '-preset', 'medium', '-crf', '22', vC]);

execFileSync('ffmpeg', [...comun, '-i', vB, '-i', vA, '-i', vC, '-filter_complex',
  `[0:v][1:v][2:v]concat=n=3:v=1:a=0[c];[c]ass=${path.join(TMP, 'rotulos.ass')}[v]`,
  '-map', '[v]', '-c:v', 'libx264', '-preset', 'medium', '-crf', '21',
  '-pix_fmt', 'yuv420p', '-movflags', '+faststart', SALIDA]);

console.log(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration,size',
  '-of', 'default=nw=1', SALIDA]).toString().trim());
console.log(SALIDA);
