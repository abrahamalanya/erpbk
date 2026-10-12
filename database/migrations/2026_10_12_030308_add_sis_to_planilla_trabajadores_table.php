<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aporte SIS del empleador por línea y si el trabajador iba por SIS (en vez
     * de EsSalud) al registrarla — copia del usuario para que una planilla
     * cerrada no cambie si después se edita el usuario.
     */
    public function up(): void
    {
        Schema::table('planilla_trabajadores', function (Blueprint $table): void {
            $table->boolean('es_sis')->default(false)->after('es_afp');
            $table->decimal('sis', 12, 2)->default(0)->after('essalud_empleador');
        });
    }

    public function down(): void
    {
        Schema::table('planilla_trabajadores', function (Blueprint $table): void {
            $table->dropColumn(['es_sis', 'sis']);
        });
    }
};
