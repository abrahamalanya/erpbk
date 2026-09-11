<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reemplaza distrito/provincia/departamento (texto libre) por una FK al
     * catálogo de ubigeo, y agrega la dirección del negocio/trabajo del
     * cliente (mismo shape que la de casa) más coordenadas GPS de ambas —
     * las columnas de texto libre viejas se sueltan en una migración
     * posterior, una vez migrados los datos existentes.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->foreignId('ubigeo_distrito_id')->nullable()->after('direccion')->constrained('ubigeo_distritos')->nullOnDelete();
            $table->decimal('latitud', 10, 7)->nullable()->after('referencia');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');

            $table->string('direccion_negocio')->nullable()->after('longitud');
            $table->foreignId('ubigeo_distrito_negocio_id')->nullable()->after('direccion_negocio')->constrained('ubigeo_distritos')->nullOnDelete();
            $table->text('referencia_negocio')->nullable()->after('ubigeo_distrito_negocio_id');
            $table->decimal('latitud_negocio', 10, 7)->nullable()->after('referencia_negocio');
            $table->decimal('longitud_negocio', 10, 7)->nullable()->after('latitud_negocio');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ubigeo_distrito_id');
            $table->dropConstrainedForeignId('ubigeo_distrito_negocio_id');
            $table->dropColumn([
                'latitud', 'longitud',
                'direccion_negocio', 'referencia_negocio', 'latitud_negocio', 'longitud_negocio',
            ]);
        });
    }
};
