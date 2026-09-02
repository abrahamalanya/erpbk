<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Garantía de un crédito hipotecario. Comparte las columnas comunes de
     * garantía con `bienes` / `vehiculos` (empresa/agencia/cliente/
     * registrado_por, valorización, precio_venta, estado, foto/video) más
     * los datos de la partida registral SUNARP del predio.
     */
    public function up(): void
    {
        Schema::create('inmuebles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('partida_registral');
            $table->string('oficina_registral')->nullable();
            $table->string('tipo_inmueble')->nullable();
            $table->string('direccion');
            $table->string('distrito')->nullable();
            $table->string('provincia')->nullable();
            $table->string('departamento')->nullable();
            $table->decimal('area_terreno', 12, 2)->nullable();
            $table->decimal('area_construida', 12, 2)->nullable();
            $table->string('propietario');
            $table->boolean('con_gravamen')->default(false);
            $table->text('linderos')->nullable();
            $table->text('observacion')->nullable();

            $table->decimal('valorizacion', 14, 2);
            $table->decimal('precio_venta', 14, 2)->nullable();
            $table->unsignedTinyInteger('puntaje')->nullable();

            $table->string('foto_cliente_producto_path')->nullable();
            $table->string('video_path')->nullable();

            $table->string('estado')->default('en_garantia');

            $table->timestamps();

            $table->index(['empresa_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inmuebles');
    }
};
