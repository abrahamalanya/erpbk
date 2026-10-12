<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teléfonos múltiples del cliente (distintos del WhatsApp, que vive en
     * `clientes.whatsapp`): una fila por número, mismo shape que
     * `cliente_fotos` / `garantia_fotos`, para poder borrar uno suelto y
     * conservar el orden.
     */
    public function up(): void
    {
        Schema::create('cliente_telefonos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained()->cascadeOnDelete();
            $table->string('numero');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['cliente_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_telefonos');
    }
};
