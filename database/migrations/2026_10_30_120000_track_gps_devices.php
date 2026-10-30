<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_gps', function (Blueprint $table) {
            $table->id();
            $table->string('identificador', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $table->timestamp('vinculado_en');
            $table->timestamps();
        });
        Schema::table('ubicaciones_asesores', function (Blueprint $table) {
            $table->index('user_id');
        });
        Schema::table('ubicaciones_asesores', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->foreignId('dispositivo_id')->nullable()->unique()->constrained('dispositivos_gps')->restrictOnDelete();
        });
        Schema::table('ubicacion_asesor_historial', function (Blueprint $table) {
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos_gps')->restrictOnDelete();
            $table->index(['user_id', 'dispositivo_id', 'capturado_en'], 'historial_dispositivo_fecha');
        });
        Schema::table('visitas_cliente', function (Blueprint $table) {
            $table->index('cliente_id');
        });
        Schema::table('visitas_cliente', function (Blueprint $table) {
            $table->dropUnique(['cliente_id', 'asesor_id', 'fecha']);
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos_gps')->restrictOnDelete();
            $table->unique(['cliente_id', 'asesor_id', 'fecha', 'dispositivo_id'], 'visita_cliente_dispositivo_dia');
        });
    }

    public function down(): void
    {
        // Reverting would merge devices and discard positions. Require explicit data reconciliation.
        throw new RuntimeException('Concilie las ubicaciones por asesor antes de revertir el GPS por dispositivo.');
    }
};
