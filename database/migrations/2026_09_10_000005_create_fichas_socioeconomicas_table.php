<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ficha socioeconómica del cliente (1:1). La usan los créditos
     * hipotecarios para armar el documento del mismo nombre. Los totales
     * (ingresos, egresos personales, egresos negocio, neto) y la edad NO se
     * guardan: se calculan al vuelo.
     */
    public function up(): void
    {
        Schema::create('fichas_socioeconomicas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->unique()->constrained('clientes')->cascadeOnDelete();

            // 1. Datos personales (extra a los del cliente)
            $table->string('grado_instruccion')->nullable();
            $table->string('profesion')->nullable();

            // 2. Datos laborales
            $table->string('laboral_institucion')->nullable();
            $table->string('laboral_cargo')->nullable();
            $table->date('laboral_fecha_ingreso')->nullable();

            // 4. Datos económicos — ingresos mensuales
            foreach ([
                'ing_conyuge', 'ing_renta1', 'ing_renta2', 'ing_renta3', 'ing_renta4',
                'ing_renta5', 'ing_otros_no_formales', 'ing_pension_judicial',
            ] as $col) {
                $table->decimal($col, 12, 2)->default(0);
            }

            // Egresos mensuales personales
            foreach ([
                'egp_alimentacion', 'egp_creditos', 'egp_educacion', 'egp_pasajes',
                'egp_agua', 'egp_luz', 'egp_telefono', 'egp_salud', 'egp_otros',
                'egp_impuestos', 'egp_cable',
            ] as $col) {
                $table->decimal($col, 12, 2)->default(0);
            }

            // Egresos mensuales negocio
            foreach ([
                'egn_alquiler', 'egn_equipo', 'egn_energia_electrica', 'egn_sueldos_cargas',
                'egn_agua', 'egn_luz', 'egn_atenciones_personal', 'egn_telefonia',
                'egn_otros_impuestos_tasas', 'egn_seguridad_limpieza', 'egn_suministros',
            ] as $col) {
                $table->decimal($col, 12, 2)->default(0);
            }

            // 5. Datos de la vivienda
            $table->string('viv_tenencia')->nullable();      // propia|alquilada|hipoteca|alojado|otros
            $table->string('viv_material')->nullable();       // noble_acabado|noble_construccion|rustico|provisional|seminoble
            $table->string('viv_habitaciones')->nullable();   // uno|dos|tres|cuatro|cinco_a_mas
            $table->string('viv_tipo')->nullable();           // casa_independiente|departamento|multifamiliar|quinta|cuarto_solo
            $table->unsignedInteger('viv_nro_pisos')->nullable();
            $table->unsignedInteger('viv_piso_vive')->nullable();
            $table->string('viv_agua')->nullable();
            $table->string('viv_telefono')->nullable();
            $table->json('viv_redes_servicio')->nullable();   // [luz_electrica, cable, desague, internet]
            $table->json('viv_bienes_muebles')->nullable();   // [equipo_sonido, cocina_gas, ...]
            $table->decimal('viv_total_activo_mueble', 12, 2)->default(0);
            $table->decimal('viv_total_activo_inmueble', 12, 2)->default(0);

            // Declarante (cuando el contacto es otro familiar) + cierre
            $table->string('declarante_nombres')->nullable();
            $table->string('declarante_parentesco')->nullable();
            $table->string('declarante_direccion')->nullable();
            $table->string('declarante_telefono')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('responsable_ficha')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas_socioeconomicas');
    }
};
