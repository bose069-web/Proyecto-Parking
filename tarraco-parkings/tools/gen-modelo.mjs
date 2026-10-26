#!/usr/bin/env node
/**
 * Genera los diagramas del modelo lógico del Sprint 2.
 *
 * Usa el motor Graphviz que ya está instalado en el SSD para el Sprint 1:
 * no descarga nada y funciona sin red. La ruta es relativa al árbol del
 * proyecto, así que el SSD sigue siendo portable.
 *
 *   node tools/gen-modelo.mjs
 *
 * Escribe:
 *   docs/img/modelo-er.svg            vista de relaciones y cardinalidades
 *   docs/img/modelo-er-atributos.svg  vista completa con atributos y claves
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const WASM = resolve(
    ROOT,
    '../../Projecte1-Sprint1/treball/node_modules/@hpcc-js/wasm/dist/graphviz.js'
);

const { Graphviz } = await import(pathToFileURL(WASM).href);

// ---------------------------------------------------------------------------
// Modelo lógico. Es la definición que se dibuja; el DDL de db/init.sql es su
// traducción física. Si cambia una relación, se cambia aquí y en el SQL.
// ---------------------------------------------------------------------------
const ENTIDADES = [
    {
        nombre: 'CLIENTE', relleno: '#E8F1FB',
        atributos: [
            ['id_cliente', 'INT', 'PK'],
            ['nombre', 'VARCHAR(50)', 'NN'],
            ['apellidos', 'VARCHAR(100)', 'NN'],
            ['email', 'VARCHAR(120)', 'UQ NN'],
            ['telefono', 'VARCHAR(20)', ''],
            ['documento_ident', 'VARCHAR(20)', 'UQ NN'],
            ['password_hash', 'VARCHAR(255)', 'NN'],
            ['fecha_registro', 'DATETIME', 'NN'],
            ['estado', 'ENUM', 'NN'],
        ],
    },
    {
        nombre: 'VEHICULO', relleno: '#F7F9FC',
        atributos: [
            ['id_vehiculo', 'INT', 'PK'],
            ['id_cliente', 'INT', 'FK NN'],
            ['matricula', 'VARCHAR(10)', 'UQ NN'],
            ['marca', 'VARCHAR(50)', ''],
            ['modelo', 'VARCHAR(50)', ''],
            ['color', 'VARCHAR(30)', ''],
            ['tipo_vehiculo', 'ENUM', 'NN'],
            ['activo', 'TINYINT(1)', 'NN'],
        ],
    },
    {
        nombre: 'METODO_PAGO', relleno: '#F7F9FC',
        atributos: [
            ['id_metodo_pago', 'INT', 'PK'],
            ['id_cliente', 'INT', 'FK NN'],
            ['tipo', 'ENUM', 'NN'],
            ['descripcion', 'VARCHAR(60)', ''],
            ['token', 'VARCHAR(255)', ''],
            ['por_defecto', 'TINYINT(1)', 'NN'],
            ['activo', 'TINYINT(1)', 'NN'],
        ],
    },
    {
        nombre: 'PARKING', relleno: '#EAF4F0',
        atributos: [
            ['id_parking', 'INT', 'PK'],
            ['nombre', 'VARCHAR(80)', 'NN'],
            ['direccion', 'VARCHAR(150)', 'NN'],
            ['ciudad', 'VARCHAR(50)', 'NN'],
            ['descripcion', 'VARCHAR(255)', ''],
            ['capacidad_total', 'INT', 'NN'],
            ['horario', 'VARCHAR(80)', 'NN'],
            ['latitud', 'DECIMAL(10,7)', ''],
            ['longitud', 'DECIMAL(10,7)', ''],
            ['activo', 'TINYINT(1)', 'NN'],
        ],
    },
    {
        nombre: 'PLAZA', relleno: '#F7F9FC',
        atributos: [
            ['id_plaza', 'INT', 'PK'],
            ['id_parking', 'INT', 'FK NN'],
            ['codigo_plaza', 'VARCHAR(10)', 'UQ+ NN'],
            ['planta', 'INT', 'NN'],
            ['tipo_plaza', 'ENUM', 'NN'],
            ['estado', 'ENUM', 'NN'],
        ],
    },
    {
        nombre: 'TARIFA', relleno: '#F7F9FC',
        atributos: [
            ['id_tarifa', 'INT', 'PK'],
            ['id_parking', 'INT', 'FK NN'],
            ['tipo_plaza', 'ENUM', 'NN'],
            ['descripcion', 'VARCHAR(80)', 'NN'],
            ['precio_hora', 'DECIMAL(10,2)', 'NN'],
            ['porcentaje_gestion', 'DECIMAL(5,4)', 'NN'],
            ['fecha_inicio_vigencia', 'DATE', 'NN'],
            ['fecha_fin_vigencia', 'DATE', ''],
            ['activa', 'TINYINT(1)', 'NN'],
        ],
    },
    {
        nombre: 'RESERVA', relleno: '#FFF3E0',
        atributos: [
            ['id_reserva', 'BIGINT', 'PK'],
            ['id_cliente', 'INT', 'FK NN'],
            ['id_vehiculo', 'INT', 'FK NN'],
            ['id_parking', 'INT', 'FK NN'],
            ['id_plaza', 'INT', 'FK'],
            ['id_tarifa', 'INT', 'FK NN'],
            ['codigo_reserva', 'VARCHAR(16)', 'UQ NN'],
            ['fecha_hora_entrada_prevista', 'DATETIME', 'NN'],
            ['fecha_hora_salida_prevista', 'DATETIME', 'NN'],
            ['fecha_hora_entrada_real', 'DATETIME', ''],
            ['fecha_hora_salida_real', 'DATETIME', ''],
            ['estado', 'ENUM(7)', 'NN'],
            ['importe_base', 'DECIMAL(10,2)', 'NN'],
            ['importe_gestion', 'DECIMAL(10,2)', 'NN'],
            ['importe_total', 'DECIMAL(10,2)', 'NN'],
            ['importe_exceso', 'DECIMAL(10,2)', 'NN'],
            ['observaciones', 'VARCHAR(255)', ''],
            ['fecha_creacion', 'DATETIME', 'NN'],
        ],
    },
    {
        nombre: 'PAGO', relleno: '#F7F9FC',
        atributos: [
            ['id_pago', 'BIGINT', 'PK'],
            ['id_reserva', 'BIGINT', 'FK NN'],
            ['id_metodo_pago', 'INT', 'FK NN'],
            ['tipo_pago', 'ENUM', 'NN'],
            ['importe', 'DECIMAL(10,2)', 'NN'],
            ['estado', 'ENUM', 'NN'],
            ['fecha_hora', 'DATETIME', 'NN'],
            ['referencia_transaccion', 'VARCHAR(64)', 'UQ'],
        ],
    },
    {
        nombre: 'REGISTRO_ACCESO', relleno: '#F7F9FC',
        atributos: [
            ['id_registro_acceso', 'BIGINT', 'PK'],
            ['id_parking', 'INT', 'FK NN'],
            ['id_vehiculo', 'INT', 'FK'],
            ['id_reserva', 'BIGINT', 'FK'],
            ['matricula_leida', 'VARCHAR(10)', 'NN'],
            ['tipo_movimiento', 'ENUM', 'NN'],
            ['fecha_hora', 'DATETIME', 'NN'],
            ['autorizado', 'TINYINT(1)', 'NN'],
            ['motivo_denegacion', 'VARCHAR(120)', ''],
            ['observaciones', 'VARCHAR(255)', ''],
        ],
    },
    {
        nombre: 'ADMINISTRADOR', relleno: '#F3EEF8',
        atributos: [
            ['id_admin', 'INT', 'PK'],
            ['nombre', 'VARCHAR(80)', 'NN'],
            ['email', 'VARCHAR(120)', 'UQ NN'],
            ['password_hash', 'VARCHAR(255)', 'NN'],
            ['rol', 'ENUM', 'NN'],
            ['activo', 'TINYINT(1)', 'NN'],
            ['fecha_alta', 'DATETIME', 'NN'],
        ],
    },
    {
        nombre: 'REGISTRO_AUDITORIA', relleno: '#F7F9FC',
        atributos: [
            ['id_auditoria', 'BIGINT', 'PK'],
            ['id_admin', 'INT', 'FK'],
            ['tabla_afectada', 'VARCHAR(40)', 'NN'],
            ['registro_id', 'BIGINT', ''],
            ['accion', 'ENUM', 'NN'],
            ['datos_anteriores', 'JSON', ''],
            ['datos_nuevos', 'JSON', ''],
            ['fecha_hora', 'DATETIME', 'NN'],
            ['direccion_ip', 'VARCHAR(45)', ''],
        ],
    },
];

// [origen, destino, verbo, cardinalidad origen, cardinalidad destino]
const RELACIONES = [
    ['CLIENTE', 'VEHICULO', 'tiene', '1', '0..*'],
    ['CLIENTE', 'METODO_PAGO', 'registra', '1', '0..*'],
    ['CLIENTE', 'RESERVA', 'realiza', '1', '0..*'],
    ['VEHICULO', 'RESERVA', 'se reserva en', '1', '0..*'],
    ['PARKING', 'PLAZA', 'contiene', '1', '0..*'],
    ['PARKING', 'TARIFA', 'aplica', '1', '0..*'],
    ['PARKING', 'RESERVA', 'recibe', '1', '0..*'],
    ['PLAZA', 'RESERVA', 'se asigna a', '0..1', '0..*'],
    ['TARIFA', 'RESERVA', 'valoriza', '1', '0..*'],
    ['RESERVA', 'PAGO', 'genera', '1', '1..*'],
    ['METODO_PAGO', 'PAGO', 'cobra con', '1', '0..*'],
    ['RESERVA', 'REGISTRO_ACCESO', 'origina', '0..1', '0..*'],
    ['VEHICULO', 'REGISTRO_ACCESO', 'protagoniza', '0..1', '0..*'],
    ['PARKING', 'REGISTRO_ACCESO', 'ocurre en', '1', '0..*'],
    ['ADMINISTRADOR', 'REGISTRO_AUDITORIA', 'ejecuta', '0..1', '0..*'],
];

// Columnas del diagrama (rankdir=LR: cada rank es una columna vertical).
const COLUMNAS = [
    ['CLIENTE', 'PARKING', 'ADMINISTRADOR'],
    ['VEHICULO', 'METODO_PAGO', 'PLAZA', 'TARIFA'],
    ['RESERVA'],
    ['PAGO', 'REGISTRO_ACCESO', 'REGISTRO_AUDITORIA'],
];

// ---------------------------------------------------------------------------
// Construcción del DOT
// ---------------------------------------------------------------------------
const esc = (s) => String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

function nodo(entidad, conAtributos) {
    if (!conAtributos) {
        return `  ${entidad.nombre} [label=<
    <TABLE BORDER="1" CELLBORDER="0" CELLSPACING="0" CELLPADDING="9" COLOR="#2C3E50" BGCOLOR="${entidad.relleno}">
      <TR><TD><FONT POINT-SIZE="11"><B>${entidad.nombre}</B></FONT></TD></TR>
    </TABLE>>];`;
    }

    const filas = entidad.atributos.map(([nombre, tipo, marcas]) => {
        const esClave = marcas.includes('PK');
        const color = esClave ? '#B03A2E' : (marcas.includes('FK') ? '#6C3483' : '#7F8C8D');
        const marca = marcas
            ? `<TD ALIGN="LEFT" WIDTH="34"><FONT COLOR="${color}" POINT-SIZE="7">${marcas.replace('UQ+', 'UQ')}</FONT></TD>`
            : '<TD></TD>';
        return `<TR><TD ALIGN="LEFT">${esc(nombre)} <FONT COLOR="#7F8C8D">${esc(tipo)}</FONT></TD>${marca}</TR>`;
    }).join('\n      ');

    return `  ${entidad.nombre} [label=<
    <TABLE BORDER="1" CELLBORDER="1" CELLSPACING="0" CELLPADDING="4" COLOR="#2C3E50" BGCOLOR="#FFFFFF">
      <TR><TD COLSPAN="2" BGCOLOR="${entidad.relleno}"><FONT POINT-SIZE="11"><B>${entidad.nombre}</B></FONT></TD></TR>
      ${filas}
    </TABLE>>];`;
}

function generar(conAtributos) {
    const lineas = [];
    lineas.push('digraph MODELO {');
    lineas.push('  graph [rankdir=LR, splines=true, nodesep=0.45, ranksep=1.15,');
    lineas.push('         fontname="Helvetica", bgcolor="transparent", pad=0.25];');
    lineas.push('  node [shape=plaintext, fontname="Helvetica", fontsize=9];');
    lineas.push('  edge [fontname="Helvetica", fontsize=8, color="#40566E",');
    lineas.push('        fontcolor="#40566E", arrowsize=0.7, penwidth=1.1];');

    for (const entidad of ENTIDADES) {
        lineas.push(nodo(entidad, conAtributos));
    }

    for (const [origen, destino, verbo, c1, c2] of RELACIONES) {
        lineas.push(`  ${origen} -> ${destino} [label="${verbo}\\n${c1} : ${c2}"];`);
    }

    for (const columna of COLUMNAS) {
        lineas.push(`  { rank=same; ${columna.join('; ')}; }`);
    }

    lineas.push('}');
    return lineas.join('\n');
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
const graphviz = await Graphviz.load();
const salida = join(ROOT, 'docs', 'img');
mkdirSync(salida, { recursive: true });

for (const [archivo, conAtributos] of [
    ['modelo-er.svg', false],
    ['modelo-er-atributos.svg', true],
]) {
    const dot = generar(conAtributos);
    writeFileSync(join(salida, archivo.replace('.svg', '.dot')), dot, 'utf8');
    const svg = graphviz.layout(dot, 'svg', 'dot');
    writeFileSync(join(salida, archivo), svg, 'utf8');
    console.log(`  ${archivo}: ${dot.split('\n').length} líneas de DOT, ${svg.length} bytes de SVG`);
}

const js = readFileSync(join(HERE, 'gen-modelo.mjs'), 'utf8');
console.log(`Modelo: ${ENTIDADES.length} entidades, ${RELACIONES.length} relaciones, ${js.length} bytes de definición.`);
