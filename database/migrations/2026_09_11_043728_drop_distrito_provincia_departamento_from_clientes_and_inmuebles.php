<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los datos existentes ya se migraron a ubigeo_distrito_id (matcheados
     * por nombre contra el catálogo INEI) antes de correr esta migración —
     * ver commit. Los que no calzaron con ningún distrito real del catálogo
     * (valores de prueba) simplemente quedan sin ubigeo, como cualquier
     * registro nuevo sin dirección.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['distrito', 'provincia', 'departamento']);
        });

        Schema::table('inmuebles', function (Blueprint $table): void {
            $table->dropColumn(['distrito', 'provincia', 'departamento']);
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('distrito')->nullable()->after('direccion');
            $table->string('provincia')->nullable()->after('distrito');
            $table->string('departamento')->nullable()->after('provincia');
        });

        Schema::table('inmuebles', function (Blueprint $table): void {
            $table->string('distrito')->nullable()->after('direccion');
            $table->string('provincia')->nullable()->after('distrito');
            $table->string('departamento')->nullable()->after('provincia');
        });
    }
};
