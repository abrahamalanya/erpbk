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
        Schema::create('caja_ciclos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->date('fecha');
            $table->string('estado')->default('cerrada');

            $table->decimal('saldo_apertura', 12, 2)->default(0);
            $table->decimal('saldo_calculado_cierre', 12, 2)->nullable();
            $table->decimal('saldo_efectivo_cierre', 12, 2)->nullable();
            $table->decimal('saldo_arqueo_cierre', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();

            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('cierre_forzado')->default(false);
            $table->boolean('cierre_automatico')->default(false);

            $table->timestamp('abierta_at')->nullable();
            $table->timestamp('cerrada_at')->nullable();

            $table->timestamps();

            $table->index(['caja_id', 'estado']);
            $table->index(['caja_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caja_ciclos');
    }
};
