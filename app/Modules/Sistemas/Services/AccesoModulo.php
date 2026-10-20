<?php

namespace App\Modules\Sistemas\Services;

use App\Nucleo\Http\Middleware\EnsurePerfilAcceso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Associates existing API resources with the shared module catalog. */
final class AccesoModulo
{
    public const TIPOS_CREDITO = ['prendario', 'diario', 'hipotecario', 'vehicular'];

    /** @return list<array{metodo: string, ruta: string}> */
    public static function rutasPara(string $key): array
    {
        $rutas = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array(EnsurePerfilAcceso::class, $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $metodo) {
                $request = Request::create('/'.$route->uri(), $metodo);
                if (in_array($key, self::requeridos($request) ?? [], true)) {
                    $rutas[] = ['metodo' => $metodo, 'ruta' => '/'.$route->uri()];
                }
            }
        }

        return $rutas;
    }

    /** @return list<string>|null Null identifies a shared authenticated utility. */
    public static function requeridos(Request $request): ?array
    {
        $resource = $request->segment(2);
        $sub = $request->segment(3);

        if (in_array($resource, ['auth', 'impersonaciones', 'notificaciones', 'ubigeo', 'modulos', 'perfiles-acceso', 'qr', 'actualizaciones-apk'], true)) {
            return null;
        }

        if ($resource === 'dashboard') {
            return $sub === 'mapa-clientes' ? ['clientes'] : [...self::TIPOS_CREDITO, 'ventas'];
        }

        if ($resource === 'opciones' && $sub === 'clientes') {
            return ['clientes', ...self::TIPOS_CREDITO, 'solicitudes', 'ventas', 'cobranzas', 'ruta_cobranza', 'simulador', 'facturacion'];
        }

        if ($resource === 'empresas' && $request->segment(4) === 'credenciales-sunat') {
            return ['facturacion'];
        }

        if ($resource === 'conceptos' && $request->isMethod('GET')) {
            return ['conceptos', 'mi_caja', 'movimientos'];
        }

        if (in_array($resource, ['bienes', 'vehiculos', 'inmuebles'], true)) {
            return [$resource, match ($resource) {
                'bienes' => 'prendario',
                'vehiculos' => 'vehicular',
                'inmuebles' => 'hipotecario',
            }];
        }

        if ($resource === 'creditos-prendarios') {
            return $request->isMethod('POST') && $sub === null ? ['prendario'] : self::TIPOS_CREDITO;
        }

        if ($resource === 'caja') {
            return $sub === 'movimientos' ? ['mi_caja', 'movimientos'] : ['mi_caja'];
        }

        if ($resource === 'cobros') {
            return $request->isMethod('GET') ? ['cobranzas', 'reportes_cobranza'] : ['cobranzas'];
        }

        if ($resource === 'reportes') {
            return [match ($sub) {
                'movimientos-dinero' => 'reportes_movimientos',
                'cobranza-diaria' => 'reportes_cobranza',
                'cajas-apertura-cierre' => 'reportes_cajas',
                'cobranza-mensual' => 'reportes_cobranza_mensual',
                'flujo-caja' => 'reportes_flujo_caja',
                'ingresos-egresos' => 'reportes_ingresos_egresos',
                'cumpleanos' => 'reportes_cumpleanos',
                'atrasos-diarios' => 'reportes_atrasos',
                'planilla' => 'reportes_planilla',
                default => '__denegado',
            }];
        }

        return [match ($resource) {
            'empresas', 'agencias', 'usuarios', 'clientes', 'bovedas', 'billetajes', 'conceptos', 'bienes', 'vehiculos', 'inmuebles', 'ventas' => $resource,
            'cajas' => 'cajas',
            'cuentas-bancarias' => 'bovedas',
            'gestion' => 'permisos_temporales',
            'creditos-diarios' => 'diario',
            'creditos-vehiculares' => 'vehicular',
            'creditos-hipotecarios' => 'hipotecario',
            'configuraciones-credito-prendario' => 'configuracion_creditos',
            'configuraciones-venta' => 'configuracion_ventas',
            'tienda-solicitudes' => 'tienda_solicitudes',
            'tienda-productos' => 'tienda_productos',
            'facturacion', 'configuracion-facturacion' => 'facturacion',
            'planillas', 'planilla-descuentos' => 'planilla',
            'simulaciones-credito' => 'simulador',
            'rutas-cobranza', 'ubicaciones-asesores' => 'ruta_cobranza',
            default => '__denegado',
        }];
    }
}
