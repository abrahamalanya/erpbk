<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precio de oferta (opcional, menor al precio_venta) para el módulo de
     * configurar productos de la tienda virtual — ver TiendaService::actualizarPrecio().
     */
    public function up(): void
    {
        foreach (['bienes', 'vehiculos', 'inmuebles'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->decimal('precio_oferta', 10, 2)->nullable()->after('precio_venta');
            });
        }
    }

    public function down(): void
    {
        foreach (['bienes', 'vehiculos', 'inmuebles'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('precio_oferta');
            });
        }
    }
};
