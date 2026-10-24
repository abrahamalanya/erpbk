<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dónde se cobra al cliente: su casa o su negocio. Decide qué par de
     * coordenadas (las de `direccion`/`latitud`/`longitud` o las de
     * `*_negocio`) usan la Ruta de Cobranza y el mapa del Inicio.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('lugar_cobranza')->default('casa')->after('estado'); // casa | negocio
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn('lugar_cobranza');
        });
    }
};
