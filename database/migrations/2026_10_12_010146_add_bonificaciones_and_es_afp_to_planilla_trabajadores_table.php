<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda los inputs de la línea que antes solo se usaban para calcular:
     * `ingresos` ya incluye las bonificaciones y no se podía separar, y si el
     * trabajador está en AFP no quedaba registrado. El frontend los necesita
     * para precargar la edición de una línea.
     */
    public function up(): void
    {
        Schema::table('planilla_trabajadores', function (Blueprint $table): void {
            $table->decimal('bonificaciones', 12, 2)->default(0)->after('ingresos');
            $table->boolean('es_afp')->default(false)->after('bonificaciones');
        });

        DB::table('planilla_trabajadores')->where('afp', '>', 0)->update(['es_afp' => true]);
    }

    public function down(): void
    {
        Schema::table('planilla_trabajadores', function (Blueprint $table): void {
            $table->dropColumn(['bonificaciones', 'es_afp']);
        });
    }
};
