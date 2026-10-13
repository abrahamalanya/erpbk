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
        Schema::create('impersonaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonado_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('token_id')->index();
            $table->string('motivo')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('finalizado_at')->nullable();
            $table->timestamps();

            $table->index(['impersonado_id', 'finalizado_at']);
            $table->index(['iniciado_por', 'finalizado_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('impersonaciones');
    }
};
