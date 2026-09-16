<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Última ubicación GPS conocida de cada asesor (una fila por asesor,
     * se sobrescribe en cada ping del tracking en segundo plano — no se
     * guarda trayectoria/historial). empresa_id/agencia_id se copian del
     * asesor al momento de registrar para poder scopear el canal de
     * broadcast sin hacer join contra users.
     */
    public function up(): void
    {
        Schema::create('ubicaciones_asesores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->decimal('precision_metros', 8, 2)->nullable();
            $table->timestamp('capturado_en');
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones_asesores');
    }
};
