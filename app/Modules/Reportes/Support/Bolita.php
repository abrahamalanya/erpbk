<?php

namespace App\Modules\Reportes\Support;

/**
 * La bolita de color de las columnas de atraso, en los dos formatos en que
 * sale: PDF (círculo real) y Excel (relleno de celda, porque en una hoja de
 * cálculo no se puede dibujar un círculo).
 *
 * Existe como clase y no como un string suelto porque dompdf NO soporta
 * border-radius: 50% — un <span> redondeado sale cuadrado en el PDF. La
 * única forma de un círculo de verdad es un SVG inline, y el SVG necesita el
 * color incrustado. Duplicar esa lógica en la vista y en el controlador es
 * justo donde se desincronizan.
 *
 * Un color null = sin bolita (la fila sale al día, sin tinte).
 */
final class Bolita
{
    /** Lado del SVG en unidades de vista. El render real queda ~0.75pt por unidad. */
    private const LADO = 10;

    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(public readonly string $hex) {}

    public static function desde(?string $hex): ?self
    {
        if ($hex === null || trim($hex) === '') {
            return null;
        }

        $hex = ltrim(trim($hex), '#');

        return self::$cache[$hex] ??= new self($hex);
    }

    /** Los 6 dígitos que espera PhpSpreadsheet para el ARGB y el cálculo de contraste. */
    public function hex(): string
    {
        return $this->hex;
    }

    /**
     * Relleno de celda en .xlsx. dompdf no está en el medio: el círculo lo
     * dibuja la vista Blade con este mismo SVG.
     */
    public function dataUri(): string
    {
        $lado = self::LADO;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$lado.'" height="'.$lado.'" viewBox="0 0 '.$lado.' '.$lado.'">'
            .'<circle cx="'.($lado / 2).'" cy="'.($lado / 2).'" r="'.($lado / 2).'" fill="#'.$this->hex.'"/>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
