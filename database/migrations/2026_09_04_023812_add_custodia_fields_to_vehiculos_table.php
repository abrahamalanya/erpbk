<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de custodia que se recogen al registrar el vehículo como
     * garantía: si el cliente dejó la llave y la tarjeta de propiedad
     * originales en la agencia.
     */
    public function up(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->boolean('dejo_llave')->default(false)->after('tiene_soat');
            $table->boolean('dejo_tarjeta_propiedad')->default(false)->after('dejo_llave');
        });
    }

    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropColumn(['dejo_llave', 'dejo_tarjeta_propiedad']);
        });
    }
};
