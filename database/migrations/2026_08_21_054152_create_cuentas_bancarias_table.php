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
        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boveda_id')->constrained()->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('banco_id')->constrained()->restrictOnDelete();

            $table->string('numero_cuenta');
            $table->string('titular');
            $table->string('tipo_cuenta')->nullable();
            $table->string('moneda')->default('PEN');
            $table->string('alias')->nullable();
            $table->boolean('activa')->default(true);
            $table->decimal('saldo_inicial', 12, 2)->default(0);

            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['boveda_id', 'activa']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuentas_bancarias');
    }
};
