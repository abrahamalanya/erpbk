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
        Schema::create('billetajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_ciclo_id')->constrained()->restrictOnDelete();
            $table->foreignId('boveda_id')->constrained()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->decimal('monto', 12, 2);
            $table->string('estado')->default('pendiente');

            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_rechazo')->nullable();

            $table->timestamp('fecha_resolucion')->nullable();

            $table->timestamps();

            $table->index(['boveda_id', 'estado']);
            $table->index('caja_ciclo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billetajes');
    }
};
