<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Me interesa" del storefront público sobre cualquier garantía en venta
     * (bien, vehículo, ...); articulo_type/articulo_id es polimórfico.
     */
    public function up(): void
    {
        Schema::create('intereses', function (Blueprint $table) {
            $table->id();
            $table->string('articulo_type');
            $table->unsignedBigInteger('articulo_id');
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();

            $table->string('nombre');
            $table->string('telefono');
            $table->string('email')->nullable();
            $table->text('mensaje')->nullable();
            $table->timestamp('atendido_at')->nullable();

            $table->timestamps();

            $table->index(['articulo_type', 'articulo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intereses');
    }
};
