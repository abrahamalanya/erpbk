<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A billetaje is now enviado POR un administrador (sistemas /
     * administrador_general / administrador_agencia) A un asesor — que solo
     * puede aceptarlo o rechazarlo. `asesor_id` es ese destinatario; sigue
     * siendo quien tiene la caja aperturada que recibe el dinero
     * (caja_ciclo_id), mientras `solicitado_por` pasa a registrar al
     * administrador que lo envió.
     */
    public function up(): void
    {
        Schema::table('billetajes', function (Blueprint $table) {
            $table->foreignId('asesor_id')->nullable()->after('boveda_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billetajes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asesor_id');
        });
    }
};
