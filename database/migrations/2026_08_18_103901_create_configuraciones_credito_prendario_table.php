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
        Schema::create('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tipo');

            $table->decimal('interes_default', 5, 2);
            $table->unsignedInteger('plazo_dias');
            $table->unsignedInteger('dias_espera_mora');
            $table->decimal('tasa_mora_diaria', 5, 2);
            $table->unsignedInteger('max_refrendos')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'agencia_id', 'tipo'], 'config_credito_prendario_empresa_agencia_tipo_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuraciones_credito_prendario');
    }
};
