<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tope de cuotas que un asesor puede indicar al registrar un crédito de
     * este tipo. Default 1 conserva el comportamiento actual de prendario;
     * el admin lo sube (p. ej. a 12) para vehicular / hipotecario.
     */
    public function up(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table): void {
            $table->unsignedInteger('max_cuotas')->default(1)->after('max_refrendos');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table): void {
            $table->dropColumn('max_cuotas');
        });
    }
};
