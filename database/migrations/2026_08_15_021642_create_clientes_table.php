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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();
            $table->foreignId('asesor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nombre');
            $table->string('apellido');
            $table->string('tipo_documento');
            $table->string('numero_documento');
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->text('referencia')->nullable();

            $table->string('foto_cliente_path')->nullable();
            $table->string('foto_dni_path')->nullable();
            $table->string('foto_casa_path')->nullable();
            $table->string('foto_negocio_path')->nullable();

            $table->string('estado')->default('activo');
            $table->timestamps();

            $table->unique(['empresa_id', 'numero_documento']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
