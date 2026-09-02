<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Garantía de un crédito vehicular. Shares the common garantía columns
     * with `bienes` (empresa/agencia/cliente/registrado_por, valorización,
     * precio_venta, estado, foto/video) plus the fields that appear on a
     * tarjeta de propiedad vehicular.
     */
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('placa');
            $table->string('motor');
            $table->string('serie');
            $table->string('color');
            $table->string('marca');
            $table->string('modelo')->nullable();
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('clase')->nullable();
            $table->string('propietario');
            $table->boolean('tiene_soat')->default(false);
            $table->text('observacion')->nullable();

            $table->decimal('valorizacion', 12, 2);
            $table->decimal('precio_venta', 12, 2)->nullable();
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
        Schema::dropIfExists('vehiculos');
    }
};
