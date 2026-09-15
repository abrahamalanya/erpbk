<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferencia de orden de visita (ruta de cobranza) de cada asesor sobre
     * sus propios clientes — persistente entre días: una vez que el asesor
     * ordena a un cliente, esa posición se mantiene; los clientes nuevos que
     * entren en mora se agregan al final automáticamente.
     */
    public function up(): void
    {
        Schema::create('clientes_ruta_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('asesor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->timestamps();

            $table->unique(['asesor_id', 'cliente_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes_ruta_orden');
    }
};
