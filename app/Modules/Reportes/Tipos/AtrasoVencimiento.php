<?php

namespace App\Modules\Reportes\Tipos;

use Carbon\CarbonInterface;

/**
 * Semáforo de riesgo del atraso del CRÉDITO COMPLETO (fecha_vencimiento vs
 * hoy), 6 tramos. Solo pinta: quién no ha vencido todavía no es una categoría
 * (desdeDias() devuelve null) y por eso no tiene ni color ni etiqueta.
 *
 * OJO con el rank: NO es "más días = rank más alto". Va al revés — 1 es la
 * mora activa más grave y 6 el Castigado, que el reporte empuja SIEMPRE al
 * final aunque sea el más grave en días (ya está perdido, no compite por la
 * cabeza de la tabla). Los créditos al día (dias <= 0) se cuelgan en el
 * escalón 5 junto a Normal para que el orden no los deje varados al principio.
 */
enum AtrasoVencimiento: string
{
    case Perdida = 'perdida';
    case Dudoso = 'dudoso';
    case Deficiente = 'deficiente';
    case ProblemaPotencial = 'problema_potencial';
    case Normal = 'normal';
    case Castigado = 'castigado';

    /** Escalón de orden para lo que aún no venció (dias <= 0): mismo nivel que Normal, sin color. */
    public const SIN_CLASIFICAR_RANK = 5;

    /**
     * Días de atraso contra el vencimiento del crédito completo, ya sea el
     * reporte de atrasos o la línea de tiempo del panel inicial. Vive acá y no
     * duplicado en los dos servicios para que "días vencidos" sea un solo
     * número en todo el sistema: si cada consumidor midiera con su propia regla,
     * el gráfico del inicio podría pintar en rojo un crédito que el reporte
     * muestra en verde.
     *
     * 0 cuando el crédito aún no vence (o no tiene fecha) — diffInDays() es
     * absoluto y daría un número positivo falso para un vencimiento futuro.
     */
    public static function diasDeAtraso(?CarbonInterface $vencimiento, CarbonInterface $hoy): int
    {
        $dia = $vencimiento?->copy()->startOfDay();

        if ($dia === null || $dia->gte($hoy)) {
            return 0;
        }

        return (int) $dia->diffInDays($hoy);
    }

    /**
     * Clasifica los días de atraso del crédito completo. 0 o menos = al día.
     */
    public static function desdeDias(int $dias): ?self
    {
        return match (true) {
            $dias > 60 => self::Castigado,
            $dias > 30 => self::Perdida,
            $dias > 20 => self::Dudoso,
            $dias > 10 => self::Deficiente,
            $dias > 5 => self::ProblemaPotencial,
            $dias > 0 => self::Normal,
            default => null,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal => '#22c55e',
            self::ProblemaPotencial => '#84cc16',
            self::Deficiente => '#f5c518',
            self::Dudoso => '#f97316',
            self::Perdida => '#ef4444',
            self::Castigado => '#000000',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::ProblemaPotencial => 'Problema potencial',
            self::Deficiente => 'Deficiente',
            self::Dudoso => 'Dudoso',
            self::Perdida => 'Pérdida',
            self::Castigado => 'Castigado',
        };
    }

    /**
     * Posición en la tabla del reporte de atrasos: 1 = mora activa más grave
     * (Pérdida), 6 = Castigado (siempre último).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Perdida => 1,
            self::Dudoso => 2,
            self::Deficiente => 3,
            self::ProblemaPotencial => 4,
            self::Normal => self::SIN_CLASIFICAR_RANK,
            self::Castigado => 6,
        };
    }

    /** Tope del tramo en días, o null si es el último (sin techo). */
    public function diasMaximos(): ?int
    {
        return match ($this) {
            self::Normal => 5,
            self::ProblemaPotencial => 10,
            self::Deficiente => 20,
            self::Dudoso => 30,
            self::Perdida => 60,
            self::Castigado => null,
        };
    }

    /**
     * Rank de una fila sin categoría (crédito al día), para que el reporte
     * siempre tenga un entero con el cual ordenar.
     */
    public static function rankSinClasificar(): int
    {
        return self::SIN_CLASIFICAR_RANK;
    }
}
