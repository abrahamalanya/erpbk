<?php

namespace App\Modules\Reportes\Tipos;

/**
 * Las 6 categorías de AtrasoVencimiento agrupadas en las 3 franjas de riesgo que
 * pide el panel inicial: verde, amarillo y rojo, más el gris de "sin clasificar"
 * para lo que todavía no venció.
 *
 * El semáforo de AtrasoVencimiento tiene 6 tramos porque en el reporte cada uno
 * necesita etiqueta propia (el supervisor tiene que poder distinguir "Dudoso"
 * de "Deficiente"). En una línea de tiempo de un vistazo eso es ilegible: con
 * muchas bandas, el frontend tendría que inventarse los cortes y acabarían
 * sin coincidir con el reporte. Acá el corte está en el enum, no en el frontend,
 * para que el gráfico y la tabla nunca se contradigan.
 *
 * NO duplica las reglas de días: el corte lo sigue haciendo
 * AtrasoVencimiento::desdeDias(), y este enum solo agrupa el resultado. Si
 * mañana se agrega una categoría nueva, hay que decidir acá en qué franja cae
 * (lanzar una excepción, no dejarla fuera en silencio).
 *
 * "Sin clasificar" no es una franja de riesgo: es la ausencia de categoría (el
 * crédito aún no vence), y por eso su color es null — el mismo criterio que
 * Bolita::desde(null) y el "sin bolita" del reporte.
 */
enum FranjaAtraso: string
{
    case Verde = 'verde';
    case Amarillo = 'amarillo';
    case Rojo = 'rojo';
    case SinClasificar = 'sin_clasificar';

    /**
     * Qué categorías de AtrasoVencimiento caen en esta franja.
     *
     * @return list<AtrasoVencimiento>
     */
    public function categorias(): array
    {
        return match ($this) {
            // Normal va con "Problema potencial": los dos son verde, el más
            // benigno manda para que la barra no tenga dos verdes distintos.
            self::Verde => [AtrasoVencimiento::Normal, AtrasoVencimiento::ProblemaPotencial],
            self::Amarillo => [AtrasoVencimiento::Deficiente, AtrasoVencimiento::Dudoso],
            // Castigado va con Pérdida. Pierde su negro propio: en una línea de
            // tiempo un tramo negro rotulado "rojo" se lee como dato faltante.
            self::Rojo => [AtrasoVencimiento::Perdida, AtrasoVencimiento::Castigado],
            self::SinClasificar => [],
        };
    }

    /**
     * Agrupa una categoría del semáforo. Null (crédito que aún no vence) va a
     * SinClasificar.
     */
    public static function desde(?AtrasoVencimiento $categoria): self
    {
        if ($categoria === null) {
            return self::SinClasificar;
        }

        foreach (self::cases() as $caso) {
            if (in_array($categoria, $caso->categorias(), true)) {
                return $caso;
            }
        }

        // Categoría nueva que nadie asignó a una franja: mejor exploitear en
        // el seed que dejar créditos fuera del 100% de la barra en silencio.
        throw new \LogicException(sprintf(
            'La categoría de AtrasoVencimiento "%s" no está en ninguna franja de FranjaAtraso.',
            $categoria->value
        ));
    }

    /**
     * Color de la franja. Null para SinClasificar, que es el gris que dibuja el
     * frontend (mismo criterio que el "sin bolita" del reporte de atrasos).
     */
    public function color(): ?string
    {
        return match ($this) {
            // Reutiliza los hex que ya muestra el reporte, no una paleta nueva:
            // el mismo crédito se ve del mismo color en el PDF y en el panel.
            self::Verde => AtrasoVencimiento::Normal->color(),
            self::Amarillo => AtrasoVencimiento::Deficiente->color(),
            self::Rojo => AtrasoVencimiento::Perdida->color(),
            self::SinClasificar => null,
        };
    }

    /**
     * Rango de días de atraso del crédito completo que cubre la franja, para
     * que la leyenda del gráfico se lea sola.
     */
    public function rango(): string
    {
        return match ($this) {
            self::Verde => '≤'.AtrasoVencimiento::ProblemaPotencial->diasMaximos().' días',
            self::Amarillo => AtrasoVencimiento::ProblemaPotencial->diasMaximos().'-'.AtrasoVencimiento::Dudoso->diasMaximos().' días',
            self::Rojo => '+'.AtrasoVencimiento::Dudoso->diasMaximos().' días',
            self::SinClasificar => 'Sin vencer',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Verde => 'Verde',
            self::Amarillo => 'Amarillo',
            self::Rojo => 'Rojo',
            self::SinClasificar => 'Sin color',
        };
    }

    /**
     * Orden de izquierda a derecha en la línea de tiempo: de menos a más grave,
     * con el gris de "sin clasificar" al final (es el estado sano, no un riesgo
     * que encaje entre verde y amarillo).
     */
    public function orden(): int
    {
        return match ($this) {
            self::Verde => 1,
            self::Amarillo => 2,
            self::Rojo => 3,
            self::SinClasificar => 4,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordenadas(): array
    {
        $casos = self::cases();

        usort($casos, fn (self $a, self $b): int => $a->orden() <=> $b->orden());

        return $casos;
    }
}
