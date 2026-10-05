<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de perfil del usuario: foto, QR de Yape, ubicación (dirección +
     * ubigeo + coordenadas) y fecha de nacimiento. Sigue el mismo shape que
     * ya usa `clientes` (foto_*_path + ubigeo_distrito_id + lat/long) para
     * que el frontend resuelva ambos formularios igual.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('foto_path')->nullable()->after('modulos');
            $table->string('qr_yape_path')->nullable()->after('foto_path');
            $table->date('fecha_nacimiento')->nullable()->after('qr_yape_path');
            $table->string('direccion')->nullable()->after('fecha_nacimiento');
            $table->text('referencia')->nullable()->after('direccion');
            $table->foreignId('ubigeo_distrito_id')->nullable()->after('referencia')->constrained('ubigeo_distritos')->nullOnDelete();
            $table->decimal('latitud', 10, 7)->nullable()->after('ubigeo_distrito_id');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ubigeo_distrito_id');
            $table->dropColumn([
                'foto_path', 'qr_yape_path', 'fecha_nacimiento',
                'direccion', 'referencia', 'latitud', 'longitud',
            ]);
        });
    }
};
