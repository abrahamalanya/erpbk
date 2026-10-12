<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `telefono` pasa a representar el número de WhatsApp del cliente; el
     * teléfono general ahora es múltiple y vive en `cliente_telefonos`
     * (ver migración `create_cliente_telefonos_table`).
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->renameColumn('telefono', 'whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->renameColumn('whatsapp', 'telefono');
        });
    }
};
