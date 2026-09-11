<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reemplaza distrito/provincia/departamento (texto libre) por una FK al
     * catálogo de ubigeo — la columna vieja se suelta en una migración
     * posterior, una vez migrados los datos existentes.
     */
    public function up(): void
    {
        Schema::table('inmuebles', function (Blueprint $table): void {
            $table->foreignId('ubigeo_distrito_id')->nullable()->after('direccion')->constrained('ubigeo_distritos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inmuebles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ubigeo_distrito_id');
        });
    }
};
