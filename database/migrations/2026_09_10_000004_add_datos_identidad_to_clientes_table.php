<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de identidad del cliente que la ficha socioeconómica necesita y
     * que hoy no se capturan. "Domicilio", "referencia de ubicación" y
     * "teléfono" ya existen como direccion / referencia / telefono; "edad"
     * se calcula desde fecha_nacimiento.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->date('fecha_nacimiento')->nullable()->after('numero_documento');
            $table->string('sexo')->nullable()->after('fecha_nacimiento'); // m | f
            $table->string('estado_civil')->nullable()->after('sexo');
            $table->string('email')->nullable()->after('estado_civil');
            $table->string('distrito')->nullable()->after('direccion');
            $table->string('provincia')->nullable()->after('distrito');
            $table->string('departamento')->nullable()->after('provincia');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn([
                'fecha_nacimiento', 'sexo', 'estado_civil', 'email',
                'distrito', 'provincia', 'departamento',
            ]);
        });
    }
};
