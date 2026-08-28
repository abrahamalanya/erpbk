<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use Illuminate\Database\Seeder;

class ConceptoSeeder extends Seeder
{
    /**
     * Default egreso concepts, seeded for every empresa. An empresa that
     * needs something specific adds it from the Conceptos admin; these are
     * the shared baseline. Stored with tipo 'gasto' (the column's value) —
     * the UI labels it "Egreso".
     *
     * @var list<string>
     */
    private const EGRESOS = [
        'Accesorios de vehículo',
        'Adelanto sueldo',
        'Agua',
        'Alimentación',
        'Combustible',
        'Gastos de alquiler',
        'Gastos pasajes aéreos',
        'Impresiones',
        'Internet',
        'Intervención policial',
        'Lavado de moto',
        'Línea de celular',
        'Mantenimiento y reparación de vehículo',
        'Medicina',
        'Otros egresos',
        'Otros gastos personales',
        'Peaje',
        'Recarga de celular',
        'SOAT',
        'Sueldo',
        'Útiles de limpieza y aseo',
        'Útiles de oficina',
        'Viáticos',
    ];

    /**
     * Default ingreso concepts, seeded for every empresa.
     *
     * @var list<string>
     */
    private const INGRESOS = [
        'Ingresos por mora',
        'Interés de crédito',
        'Otros ingresos',
        'Préstamo analista',
        'Préstamo bancario',
        'Préstamo de empresa',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Empresa::query()->each(function (Empresa $empresa): void {
            $this->seedTipo($empresa, 'gasto', self::EGRESOS);
            $this->seedTipo($empresa, 'ingreso', self::INGRESOS);
        });
    }

    /**
     * @param  list<string>  $nombres
     */
    private function seedTipo(Empresa $empresa, string $tipo, array $nombres): void
    {
        foreach ($nombres as $nombre) {
            Concepto::query()->firstOrCreate(
                ['empresa_id' => $empresa->id, 'tipo' => $tipo, 'nombre' => $nombre],
                ['activo' => true, 'creado_por' => null],
            );
        }
    }
}
