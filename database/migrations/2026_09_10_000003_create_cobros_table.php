<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de cobros sobre créditos. Se escribe una fila cada vez que
     * un crédito recibe dinero por refrendo, adenda o liquidación —
     * cualquiera sea el punto de entrada (pantalla del crédito o módulo
     * Cobranzas). Es un registro de auditoría: el movimiento de caja y el
     * cambio de estado del crédito siguen siendo la fuente contable.
     */
    public function up(): void
    {
        Schema::create('cobros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->restrictOnDelete();
            // Crédito sucesor generado por refrendo / adenda (null en liquidación).
            $table->foreignId('credito_sucesor_id')->nullable()->constrained('creditos_prendarios')->nullOnDelete();
            $table->foreignId('caja_ciclo_id')->nullable()->constrained('caja_ciclos')->nullOnDelete();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();

            $table->string('operacion'); // refrendo | adenda | liquidacion
            $table->decimal('monto_pagado', 12, 2);
            $table->string('medio'); // efectivo | yape | plin | transferencia
            $table->decimal('interes', 12, 2);
            $table->decimal('mora', 12, 2)->nullable();
            $table->decimal('vuelto', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
            $table->index('credito_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros');
    }
};
