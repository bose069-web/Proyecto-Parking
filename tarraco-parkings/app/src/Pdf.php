<?php
declare(strict_types=1);

/**
 * Generador de PDF mínimo, sin dependencias.
 *
 * Por qué no hay librería: el contenedor solo instala pdo_mysql, mbstring y
 * las extensiones de Apache, y el aula no tiene red para traer Composer. Un
 * PDF de una página con texto y líneas no necesita más que esto.
 *
 * Lo que sí hace falta y aquí se resuelve:
 *  - Codificación WinAnsi (Windows-1252), que es la que entienden las fuentes
 *    estándar del PDF. El texto llega en UTF-8 y se convierte antes de
 *    escribir, así que las tildes y el euro salen bien y no como bytes sueltos.
 *  - Anchuras reales de Helvetica para partir las líneas y alinear los
 *    importes a la derecha. Con una estimación por número de caracteres, una
 *    columna de euros queda torcida.
 *  - Varias páginas con salto automático y numeración.
 */
final class Pdf
{
    private const ANCHO = 595.28;   // A4 en puntos
    private const ALTO  = 841.89;
    private const MARGEN = 56.0;

    /** @var list<string> contenido de cada página */
    private array $paginas = [];
    private string $actual = '';
    private float $y;
    private int $numPagina = 0;

    /** @var array<int, int> anchuras AFM de Helvetica, por punto de código */
    private const ANCHURAS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
        39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
        46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556,
        53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278,
        60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667,
        67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278,
        74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
        81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469,
        95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556,
        102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500,
        108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556,
        114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334,
        126 => 584,
        // WinAnsi fuera del ASCII imprimible
        128 => 556, 130 => 222, 131 => 556, 132 => 333, 133 => 1000, 134 => 556,
        135 => 556, 136 => 333, 137 => 1000, 138 => 667, 139 => 333, 145 => 222,
        146 => 222, 147 => 333, 148 => 333, 149 => 350, 150 => 556, 151 => 1000,
        152 => 333, 153 => 1000, 154 => 500, 155 => 333, 160 => 278, 161 => 333,
        162 => 556, 163 => 556, 164 => 556, 165 => 556, 166 => 260, 167 => 556,
        168 => 333, 169 => 737, 170 => 370, 171 => 556, 172 => 556, 173 => 333,
        174 => 737, 175 => 556, 176 => 400, 177 => 584, 178 => 333, 179 => 333,
        180 => 333, 181 => 556, 182 => 537, 183 => 278, 184 => 333, 185 => 333,
        186 => 365, 187 => 556, 188 => 834, 189 => 834, 190 => 834, 191 => 611,
        192 => 667, 193 => 667, 194 => 667, 195 => 667, 196 => 667, 197 => 667,
        198 => 1000, 199 => 722, 200 => 667, 201 => 667, 202 => 667, 203 => 667,
        204 => 278, 205 => 278, 206 => 278, 207 => 278, 208 => 722, 209 => 722,
        210 => 778, 211 => 778, 212 => 778, 213 => 778, 214 => 778, 215 => 584,
        216 => 778, 217 => 722, 218 => 722, 219 => 722, 220 => 722, 221 => 667,
        222 => 667, 223 => 611, 224 => 556, 225 => 556, 226 => 556, 227 => 556,
        228 => 556, 229 => 556, 230 => 889, 231 => 500, 232 => 556, 233 => 556,
        234 => 556, 235 => 556, 236 => 278, 237 => 278, 238 => 278, 239 => 278,
        240 => 556, 241 => 556, 242 => 556, 243 => 556, 244 => 556, 245 => 556,
        246 => 556, 247 => 584, 248 => 611, 249 => 556, 250 => 556, 251 => 556,
        252 => 556, 253 => 500, 254 => 556, 255 => 556,
    ];

    public function __construct()
    {
        $this->nuevaPagina();
    }

    // ---------------------------------------------------------------- medidas

    private static function aWinAnsi(string $texto): string
    {
        if ($texto === '' || preg_match('/^[\x20-\x7E]*$/', $texto) === 1) {
            return $texto;
        }
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        if ($convertido === false || $convertido === '') {
            $convertido = @mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
        }
        return $convertido === false ? $texto : $convertido;
    }

    /** Anchura de un texto ya convertido a WinAnsi, en puntos. */
    private static function ancho(string $texto, float $cuerpo, bool $negrita = false): float
    {
        $total = 0;
        $largo = strlen($texto);
        for ($i = 0; $i < $largo; $i++) {
            $total += self::ANCHURAS[ord($texto[$i])] ?? 500;
        }
        $factor = $negrita ? 1.05 : 1.0;
        return $total * $cuerpo / 1000.0 * $factor;
    }

    private static function escapar(string $texto): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $texto);
    }

    /** Parte un texto en líneas que caben en $ancho. Respeta los saltos de línea. */
    public function partir(string $texto, float $ancho, float $cuerpo, bool $negrita = false): array
    {
        $lineas = [];
        foreach (explode("\n", $texto) as $parrafo) {
            $palabras = preg_split('/\s+/', trim($parrafo)) ?: [];
            $linea = '';
            foreach ($palabras as $palabra) {
                $candidata = $linea === '' ? $palabra : $linea . ' ' . $palabra;
                if (self::ancho(self::aWinAnsi($candidata), $cuerpo, $negrita) <= $ancho || $linea === '') {
                    $linea = $candidata;
                } else {
                    $lineas[] = $linea;
                    $linea = $palabra;
                }
            }
            $lineas[] = $linea;
        }
        return $lineas;
    }

    // ---------------------------------------------------------------- dibujo

    private function nuevaPagina(): void
    {
        if ($this->numPagina > 0) {
            $this->paginas[] = $this->actual;
        }
        $this->numPagina++;
        $this->actual = '';
        $this->y = self::ALTO - self::MARGEN;
    }

    public function paginaNueva(): void
    {
        $this->nuevaPagina();
    }

    public function y(): float
    {
        return $this->y;
    }

    public function salto(float $puntos): void
    {
        $this->y -= $puntos;
    }

    private function reservar(float $alto): void
    {
        if ($this->y - $alto < self::MARGEN + 40) {
            $this->nuevaPagina();
        }
    }

    public function texto(
        string $texto,
        float $cuerpo = 10.5,
        bool $negrita = false,
        ?float $x = null,
        ?float $ancho = null,
        string $color = '0.10 0.16 0.20',
        bool $derecha = false
    ): void {
        $x = $x ?? self::MARGEN;
        $ancho = $ancho ?? (self::ANCHO - 2 * self::MARGEN);
        foreach ($this->partir($texto, $ancho, $cuerpo, $negrita) as $linea) {
            $this->reservar($cuerpo * 1.45);
            $this->y -= $cuerpo * 1.45;
            $win = self::aWinAnsi($linea);
            $posX = $x;
            if ($derecha) {
                $posX = $x + $ancho - self::ancho($win, $cuerpo, $negrita);
            }
            $this->actual .= sprintf(
                "BT /%s %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
                $negrita ? 'F2' : 'F1',
                $cuerpo,
                $color,
                $posX,
                $this->y,
                self::escapar($win)
            );
        }
    }

    /** Fila de dos columnas: etiqueta a la izquierda, valor a la derecha. */
    public function campo(string $etiqueta, string $valor, float $cuerpo = 10.5): void
    {
        $this->reservar($cuerpo * 1.5);
        $this->y -= $cuerpo * 1.5;
        $anchoTotal = self::ANCHO - 2 * self::MARGEN;
        $anchoEtiqueta = 190.0;
        $this->actual .= sprintf(
            "BT /F1 %.2F Tf 0.42 0.47 0.49 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $cuerpo,
            self::MARGEN,
            $this->y,
            self::escapar(self::aWinAnsi($etiqueta))
        );
        $win = self::aWinAnsi($valor);
        $posX = self::MARGEN + $anchoTotal - self::ancho($win, $cuerpo, true);
        $this->actual .= sprintf(
            "BT /F2 %.2F Tf 0.10 0.16 0.20 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $cuerpo,
            max($posX, self::MARGEN + $anchoEtiqueta),
            $this->y,
            self::escapar($win)
        );
    }

    public function linea(float $grosor = 0.6, string $color = '0.80 0.78 0.74'): void
    {
        $this->reservar($grosor + 6);
        $this->y -= $grosor + 6;
        $this->actual .= sprintf(
            "%.2F w %s RG %.2F %.2F m %.2F %.2F l S\n",
            $grosor,
            $color,
            self::MARGEN,
            $this->y,
            self::ANCHO - self::MARGEN,
            $this->y
        );
    }

    /** Recuadro con una etiqueta pequeña y un valor grande, y el cursor debajo. */
    public function caja(string $etiqueta, string $valor, float $alto = 54.0): void
    {
        $this->reservar($alto + 10);
        $this->y -= $alto + 10;
        $this->actual .= sprintf(
            "0.965 0.945 0.910 rg %.2F %.2F %.2F %.2F re f\n",
            self::MARGEN,
            $this->y,
            self::ANCHO - 2 * self::MARGEN,
            $alto
        );
        $this->actual .= sprintf(
            "0.769 0.380 0.235 RG 2 w %.2F %.2F m %.2F %.2F l S\n",
            self::MARGEN,
            $this->y,
            self::MARGEN,
            $this->y + $alto
        );
        $this->actual .= sprintf(
            "BT /F1 8.5 Tf 0.42 0.30 0.20 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            self::MARGEN + 14,
            $this->y + $alto - 20,
            self::escapar(self::aWinAnsi($etiqueta))
        );
        $this->actual .= sprintf(
            "BT /F2 16 Tf 0.10 0.16 0.20 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            self::MARGEN + 14,
            $this->y + 14,
            self::escapar(self::aWinAnsi($valor))
        );
    }

    /** Recuadro de relleno; deja el cursor justo debajo. */
    public function recuadro(float $alto, string $relleno = '0.965 0.945 0.910'): void
    {
        $this->reservar($alto + 8);
        $this->y -= $alto + 8;
        $this->actual .= sprintf(
            "%s rg %.2F %.2F %.2F %.2F re f\n",
            $relleno,
            self::MARGEN,
            $this->y,
            self::ANCHO - 2 * self::MARGEN,
            $alto
        );
    }

    /** Sello de texto en diagonal, para copias y demostraciones. */
    public function marcaAgua(string $texto, float $cuerpo = 62, string $color = '0.94 0.90 0.86'): void
    {
        $this->actual .= sprintf(
            "q BT /F2 %.2F Tf %s rg 0.62 0.62 -0.62 0.62 %.2F %.2F Tm (%s) Tj ET Q\n",
            $cuerpo,
            $color,
            120.0,
            330.0,
            self::escapar(self::aWinAnsi($texto))
        );
    }

    // ---------------------------------------------------------------- salida

    public function salida(): string
    {
        $this->paginas[] = $this->actual;

        // Pie de página en todas: número y aviso.
        $total = count($this->paginas);
        foreach ($this->paginas as $i => $contenido) {
            $this->paginas[$i] = $contenido . sprintf(
                "BT /F1 8 Tf 0.55 0.58 0.60 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
                self::MARGEN,
                self::MARGEN - 8,
                self::escapar(self::aWinAnsi('Página ' . ($i + 1) . ' de ' . $total))
            );
        }

        $objetos = [];
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $numObjeto = 3;
        $idsPaginas = [];
        foreach ($this->paginas as $contenido) {
            $idsPaginas[] = $numObjeto;
            $numObjeto += 2; // página + contenido
        }
        $objetos[2] = '<< /Type /Pages /Count ' . count($this->paginas)
            . ' /Kids [' . implode(' ', array_map(static fn (int $n): string => $n . ' 0 R', $idsPaginas)) . '] >>';

        $numObjeto = 3;
        foreach ($this->paginas as $contenido) {
            $idPagina = $numObjeto;
            $idContenido = $numObjeto + 1;
            $objetos[$idPagina] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] '
                . '/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
                . '/Encoding /WinAnsiEncoding >> /F2 << /Type /Font /Subtype /Type1 '
                . '/BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >> >> >> '
                . '/Contents %d 0 R >>',
                self::ANCHO,
                self::ALTO,
                $idContenido
            );
            $objetos[$idContenido] = '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "endstream";
            $numObjeto += 2;
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posiciones = [];
        foreach ($objetos as $numero => $cuerpo) {
            $posiciones[$numero] = strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $cuerpo . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $maximo = max(array_keys($objetos));
        $pdf .= "xref\n0 " . ($maximo + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maximo; $i++) {
            $pdf .= isset($posiciones[$i])
                ? sprintf("%010d 00000 n \n", $posiciones[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maximo + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }
}
