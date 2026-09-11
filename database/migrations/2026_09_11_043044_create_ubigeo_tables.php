<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de ubigeo (INEI) — departamento/provincia/distrito de todo el
     * Perú. Reutilizado por Cliente e Inmueble (y cualquier módulo futuro que
     * necesite una dirección) en vez de que cada uno tenga sus propios campos
     * de texto libre. Datos globales, no tenant-scoped — los carga
     * UbigeoSeeder desde database/data/ubigeo/*.json.
     */
    public function up(): void
    {
        Schema::create('ubigeo_departamentos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 2)->unique();
            $table->string('nombre');
            $table->timestamps();
        });

        Schema::create('ubigeo_provincias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ubigeo_departamento_id')->constrained()->restrictOnDelete();
            $table->string('codigo', 2);
            $table->string('nombre');
            $table->timestamps();

            $table->unique(['ubigeo_departamento_id', 'codigo']);
        });

        Schema::create('ubigeo_distritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ubigeo_provincia_id')->constrained()->restrictOnDelete();
            $table->string('codigo', 2);
            $table->string('nombre');
            $table->timestamps();

            $table->unique(['ubigeo_provincia_id', 'codigo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ubigeo_distritos');
        Schema::dropIfExists('ubigeo_provincias');
        Schema::dropIfExists('ubigeo_departamentos');
    }
};
