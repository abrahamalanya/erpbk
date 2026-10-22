<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Huella SHA-256 del certificado del firmante (esquema v2), extraída por
     * ApkInspectorService al subir el APK — permite advertir en la UI cuando
     * una build nueva está firmada con un certificado distinto al de la
     * versión vigente (Android rechaza instalar/actualizar si no coinciden).
     * Nula cuando el APK solo tiene firma v1 o v3 sin v2 (no se parsea en
     * esta versión).
     */
    public function up(): void
    {
        Schema::table('apk_versions', function (Blueprint $table): void {
            $table->string('certificado_sha256', 64)->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('apk_versions', function (Blueprint $table): void {
            $table->dropColumn('certificado_sha256');
        });
    }
};
