<?php

namespace App\Modules\Reportes\Tipos;

/**
 * Semáforo del atraso a NIVEL CUOTA: días desde la cuota vencida impaga más
 * antigua. Son 5 tramos de color pero SIN categoría — a diferencia de
 * AtrasoVencimiento no hay etiqueta que mostrar, solo la bolita; el texto de
 * la celda es el conteo de cuotas ("3 Cuotas"). Al día (dias <= 0) tampoco
 * es categoría: null, sin tinte.
 *
 * Los nombres de los casos solo existen para leer el código: nadie los
 * muestra.
 */
enum AtrasoCuota: string
{
    case Reciente = 'reciente';
    case Moderado = 'moderado';
    case Serio = 'serio';
    case Critico = 'critico';
    case Severo = 'severo';

    /**
     * Clasifica los días desde la cuota vencida impaga más antigua.
     * 0 o menos = sin cuotas vencidas impagas.
     */
    public static function desdeDias(int $dias): ?self
    {
        return match (true) {
            $dias > 20 => self::Severo,
            $dias > 15 => self::Critico,
            $dias > 7 => self::Serio,
            $dias > 2 => self::Moderado,
            $dias > 0 => self::Reciente,
            default => null,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Reciente => '#84cc16',
            self::Moderado => '#38bdf8',
            self::Serio => '#fde047',
            self::Critico => '#f43f5e',
            self::Severo => '#7f1d1d',
        };
    }

    /** Tope del tramo en días, o null si es el último (sin techo). */
    public function diasMaximos(): ?int
    {
        return match ($this) {
            self::Reciente => 2,
            self::Moderado => 7,
            self::Serio => 15,
            self::Critico => 20,
            self::Severo => null,
        };
    }
}
