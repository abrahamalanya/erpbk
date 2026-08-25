<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('ruc', 11)->nullable();
            $table->string('razon_social')->nullable();
            $table->string('domicilio_legal')->nullable();
            $table->string('actividad_economica')->nullable();
            $table->string('representante_legal')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('firma_path')->nullable();
            $table->string('estado')->default('activo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
