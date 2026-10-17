<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULOS = [
        'empresas' => ['Empresas', 'administracion'],
        'agencias' => ['Agencias', 'administracion'],
        'usuarios' => ['Usuarios', 'administracion'],
        'clientes' => ['Clientes', 'administracion'],
        'permisos_temporales' => ['Permisos temporales', 'gestion'],
        'mi_caja' => ['Mi caja', 'finanzas'],
        'bovedas' => ['Bóvedas', 'finanzas'],
        'billetajes' => ['Billetajes', 'finanzas'],
        'movimientos' => ['Ingresos y egresos', 'finanzas'],
        'conceptos' => ['Conceptos', 'finanzas'],
        'cobranzas' => ['Cobranzas', 'creditos'],
        'ruta_cobranza' => ['Mi ruta de cobranza', 'creditos'],
        'simulador' => ['Simulador', 'creditos'],
        'bienes' => ['Bienes', 'creditos'],
        'vehiculos' => ['Vehículos', 'creditos'],
        'inmuebles' => ['Inmuebles', 'creditos'],
        'configuracion_creditos' => ['Configuración de créditos', 'creditos'],
        'ventas' => ['Ventas', 'ventas'],
        'configuracion_ventas' => ['Configuración de ventas', 'ventas'],
        'tienda_solicitudes' => ['Solicitudes de la tienda', 'ventas'],
        'tienda_productos' => ['Productos de la tienda', 'ventas'],
        'reportes_movimientos' => ['Movimientos de dinero', 'reportes'],
        'reportes_cobranza' => ['Cobranza diaria y anulaciones', 'reportes'],
        'reportes_cajas' => ['Cajas: aperturas y cierres', 'reportes'],
        'reportes_cobranza_mensual' => ['Cobranza y desembolso mensual', 'reportes'],
        'reportes_flujo_caja' => ['Flujo de caja', 'reportes'],
        'reportes_ingresos_egresos' => ['Ingresos y egresos', 'reportes'],
        'reportes_cumpleanos' => ['Cumpleaños', 'reportes'],
        'reportes_atrasos' => ['Reporte diario', 'reportes'],
        'reportes_planilla' => ['Reporte de planilla', 'reportes'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('perfiles_acceso', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps(6);
        });

        Schema::create('perfil_acceso_modulo', function (Blueprint $table) {
            $table->foreignId('perfil_acceso_id')->constrained('perfiles_acceso')->cascadeOnDelete();
            $table->foreignId('modulo_id')->constrained('modulos')->restrictOnDelete();
            $table->primary(['perfil_acceso_id', 'modulo_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('perfil_acceso_id')->nullable()->constrained('perfiles_acceso')->restrictOnDelete();
        });

        foreach (self::MODULOS as $key => [$nombre, $grupo]) {
            DB::table('modulos')->insertOrIgnore([
                'key' => $key, 'nombre' => $nombre, 'grupo' => $grupo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (['solicitudes' => 'creditos', 'cajas' => 'finanzas', 'planilla' => 'administracion', 'facturacion' => 'facturacion'] as $key => $grupo) {
            DB::table('modulos')->where('key', $key)->update(['grupo' => $grupo]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_acceso_id');
        });
        Schema::dropIfExists('perfil_acceso_modulo');
        Schema::dropIfExists('perfiles_acceso');
    }
};
