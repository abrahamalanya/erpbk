<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las tasas de planilla de la empresa: AFP 11.37% u ONP 13% al trabajador,
     * EsSalud 9% o SIS S/ 15 al empleador, todo sobre el sueldo básico. Se van
     * el EsSalud del trabajador y la CTS, que no se aplican.
     *
     * Las filas existentes se pasan a las tasas nuevas: nunca hubo pantalla para
     * editarlas, así que solo tenían los defaults anteriores.
     */
    public function up(): void
    {
        Schema::table('configuraciones_planilla', function (Blueprint $table): void {
            $table->decimal('onp_trabajador', 5, 2)->default(13.00)->change();
            $table->decimal('afp_trabajador', 5, 2)->default(11.37)->change();
            $table->decimal('essalud_empleador', 5, 2)->default(9.00)->change();
            $table->decimal('sis_empleador', 7, 2)->default(15.00)->after('essalud_empleador');
            $table->dropColumn(['essalud_trabajador', 'cts_empleador']);
        });

        DB::table('configuraciones_planilla')->update([
            'onp_trabajador' => 13.00,
            'afp_trabajador' => 11.37,
            'essalud_empleador' => 9.00,
            'sis_empleador' => 15.00,
        ]);
    }

    public function down(): void
    {
        Schema::table('configuraciones_planilla', function (Blueprint $table): void {
            $table->decimal('onp_trabajador', 5, 2)->default(3.00)->change();
            $table->decimal('afp_trabajador', 5, 2)->default(10.00)->change();
            $table->decimal('essalud_empleador', 5, 2)->default(5.00)->change();
            $table->dropColumn('sis_empleador');
            $table->decimal('essalud_trabajador', 5, 2)->default(4.00);
            $table->decimal('cts_empleador', 5, 2)->default(8.33);
        });
    }
};
