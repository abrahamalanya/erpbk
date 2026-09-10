<?php

namespace App\Nucleo\Support;

/**
 * Convierte un monto a su representación en letras (español, formato
 * peruano) para los documentos de cobranza. Ej: 8264.70 ->
 * "OCHO MIL DOSCIENTOS SESENTA Y CUATRO CON 70/100 SOLES".
 */
final class NumeroALetras
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE',
    ];

    private const DECENAS = [
        '', '', 'VEINTI', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA',
    ];

    private const CENTENAS = [
        '', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    public static function soles(string|float|int $monto): string
    {
        $monto = round((float) $monto, 2);
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $letras = self::enteroALetras($entero);

        return trim($letras) . ' CON ' . str_pad((string) $centavos, 2, '0', STR_PAD_LEFT) . '/100 SOLES';
    }

    private static function enteroALetras(int $n): string
    {
        if ($n === 0) {
            return 'CERO';
        }

        if ($n < 0) {
            return 'MENOS ' . self::enteroALetras(-$n);
        }

        $millones = intdiv($n, 1_000_000);
        $miles = intdiv($n % 1_000_000, 1000);
        $resto = $n % 1000;

        $partes = [];

        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'UN MILLÓN' : self::tresDigitos($millones) . ' MILLONES';
        }

        if ($miles > 0) {
            $partes[] = $miles === 1 ? 'MIL' : self::tresDigitos($miles) . ' MIL';
        }

        if ($resto > 0) {
            $partes[] = self::tresDigitos($resto);
        }

        return implode(' ', $partes);
    }

    private static function tresDigitos(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }

        $c = intdiv($n, 100);
        $d = $n % 100;

        $texto = self::CENTENAS[$c];
        $decenas = self::dosDigitos($d);

        return trim($texto . ' ' . $decenas);
    }

    private static function dosDigitos(int $n): string
    {
        if ($n <= 20) {
            return self::UNIDADES[$n];
        }

        $d = intdiv($n, 10);
        $u = $n % 10;

        if ($d === 2) {
            // 21..29 -> VEINTIUNO, VEINTIDÓS, ...
            $especiales = [1 => 'VEINTIUNO', 2 => 'VEINTIDÓS', 3 => 'VEINTITRÉS', 6 => 'VEINTISÉIS'];

            return $especiales[$u] ?? 'VEINTI' . self::UNIDADES[$u];
        }

        return $u === 0
            ? self::DECENAS[$d]
            : self::DECENAS[$d] . ' Y ' . self::UNIDADES[$u];
    }
}
