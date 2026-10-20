<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de compilaciones APK publicadas para la app móvil (ver
     * App\Modules\Sistemas\Services\ApkVersionService). El archivo vive en el
     * disco PRIVADO (nunca en `public/`); aquí solo metadata, ruta y hash —
     * mismo criterio que `credenciales_sunat.certificado_path`.
     */
    public function up(): void
    {
        Schema::create('apk_versions', function (Blueprint $table): void {
            $table->id();

            $table->string('application_id');
            $table->string('version', 20);
            $table->unsignedInteger('build_number');
            $table->text('descripcion')->nullable();

            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);

            $table->boolean('publicada')->default(false);
            $table->timestamp('publicada_at')->nullable();

            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['application_id', 'build_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apk_versions');
    }
};
