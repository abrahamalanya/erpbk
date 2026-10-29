<?php

use App\Modules\Caja\Http\Controllers\BilletajeController;
use App\Modules\Caja\Http\Controllers\BovedaController;
use App\Modules\Caja\Http\Controllers\CajaController;
use App\Modules\Caja\Http\Controllers\CuentaBancariaController;
use App\Modules\Cliente\Http\Controllers\ClienteController;
use App\Modules\Cliente\Http\Controllers\FichaSocioeconomicaController;
use App\Modules\Cobranza\Http\Controllers\CobroController;
use App\Modules\Credito\Http\Controllers\ConfiguracionCreditoController;
use App\Modules\Credito\Http\Controllers\CreditoController;
use App\Modules\Credito\Http\Controllers\CreditoExpedienteController;
use App\Modules\CreditoDiario\Http\Controllers\CreditoDiarioController;
use App\Modules\CreditoDiario\Http\Controllers\CreditoDiarioDesembolsoEvidenciaController;
use App\Modules\CreditoHipotecario\Http\Controllers\CreditoHipotecarioController;
use App\Modules\CreditoHipotecario\Http\Controllers\InmuebleController;
use App\Modules\CreditoPrendario\Http\Controllers\BienController;
use App\Modules\CreditoVehicular\Http\Controllers\CreditoVehicularController;
use App\Modules\CreditoVehicular\Http\Controllers\VehiculoController;
use App\Modules\Dashboard\Http\Controllers\DashboardController;
use App\Modules\Empresa\Http\Controllers\AgenciaController;
use App\Modules\Empresa\Http\Controllers\EmpresaController;
use App\Modules\Empresa\Http\Controllers\Facturacion\CredencialesSunatController;
use App\Modules\Facturacion\Http\Controllers\ComprobanteController;
use App\Modules\Facturacion\Http\Controllers\ConfiguracionFacturacionController;
use App\Modules\Facturacion\Http\Controllers\ReporteFacturacionController;
use App\Modules\Facturacion\Http\Controllers\SerieComprobanteController;
use App\Modules\Planilla\Http\Controllers\PlanillaController;
use App\Modules\Planilla\Http\Controllers\PlanillaDescuentoController;
use App\Modules\Qr\Http\Controllers\QrController;
use App\Modules\Reportes\Http\Controllers\ReporteAtrasosController;
use App\Modules\Reportes\Http\Controllers\ReporteCajasController;
use App\Modules\Reportes\Http\Controllers\ReporteCobranzaController;
use App\Modules\Reportes\Http\Controllers\ReporteCobranzaMensualController;
use App\Modules\Reportes\Http\Controllers\ReporteCumpleanosController;
use App\Modules\Reportes\Http\Controllers\ReporteFlujoCajaController;
use App\Modules\Reportes\Http\Controllers\ReporteIngresosEgresosController;
use App\Modules\Reportes\Http\Controllers\ReporteMovimientosController;
use App\Modules\Reportes\Http\Controllers\ReportePlanillaController;
use App\Modules\Ruta\Http\Controllers\RutaCobranzaController;
use App\Modules\Simulador\Http\Controllers\SimuladorController;
use App\Modules\Sistemas\Http\Controllers\ApkVersionController;
use App\Modules\Sistemas\Http\Controllers\AuthController;
use App\Modules\Sistemas\Http\Controllers\ConceptoController;
use App\Modules\Sistemas\Http\Controllers\ConfiguracionSistemaController;
use App\Modules\Sistemas\Http\Controllers\ModuloController;
use App\Modules\Sistemas\Http\Controllers\NotificacionController;
use App\Modules\Sistemas\Http\Controllers\PerfilAccesoController;
use App\Modules\Sistemas\Http\Controllers\PermisoTemporalController;
use App\Modules\Sistemas\Http\Controllers\PermissionController;
use App\Modules\Sistemas\Http\Controllers\RoleController;
use App\Modules\Tienda\Http\Controllers\InteresArticuloAdminController;
use App\Modules\Tienda\Http\Controllers\TiendaArticuloController;
use App\Modules\Tienda\Http\Controllers\TiendaController;
use App\Modules\Tienda\Http\Controllers\TiendaProductoController;
use App\Modules\Ubicacion\Http\Controllers\UbicacionAsesorController;
use App\Modules\Ubigeo\Http\Controllers\UbigeoController;
use App\Modules\Usuario\Http\Controllers\FotocheckController;
use App\Modules\Usuario\Http\Controllers\ImpersonacionController;
use App\Modules\Usuario\Http\Controllers\UserController;
use App\Modules\Usuario\Http\Controllers\UsuarioModuloController;
use App\Modules\Venta\Http\Controllers\CatalogoVentaController;
use App\Modules\Venta\Http\Controllers\ConfiguracionVentaController;
use App\Modules\Venta\Http\Controllers\VentaController;
use App\Nucleo\Http\Controllers\BancoController;
use App\Nucleo\Http\Middleware\EnsureIdempotency;
use App\Nucleo\Http\Middleware\EnsurePerfilAcceso;
use Illuminate\Support\Facades\Route;

// ===== AUTH ROUTES (públicas) =====
Route::post('/auth/login', [AuthController::class, 'login']);

// ===== CONFIGURACIÓN (pública en lectura: la usa el login antes de autenticarse) =====
Route::get('/configuracion', [ConfiguracionSistemaController::class, 'show'])->name('configuracion.show');

// ===== TIENDA VIRTUAL (públicas, sin auth) =====
Route::prefix('tienda')->group(function () {
    // Unificada: bienes + vehículos (+ inmuebles a futuro) en venta.
    Route::get('articulos', [TiendaArticuloController::class, 'index'])->name('tienda.articulos.index');
    Route::get('articulos/{tipo}/{id}', [TiendaArticuloController::class, 'show'])->name('tienda.articulos.show')->whereIn('tipo', ['bien', 'vehiculo', 'inmueble'])->whereNumber('id');
    Route::post('articulos/{tipo}/{id}/interes', [TiendaArticuloController::class, 'interes'])->name('tienda.articulos.interes')->whereIn('tipo', ['bien', 'vehiculo', 'inmueble'])->whereNumber('id');

    // Legacy solo-bienes (se mantiene por compatibilidad).
    Route::get('bienes', [TiendaController::class, 'index'])->name('tienda.bienes.index');
    Route::get('bienes/{bien}', [TiendaController::class, 'show'])->name('tienda.bienes.show');
    Route::post('bienes/{bien}/interes', [TiendaController::class, 'interes'])->name('tienda.bienes.interes');
});

// ===== DESCARGA DE ACTUALIZACIONES APK (pública, protegida por firma) =====
// Linking.openURL en el aplicativo no manda el Bearer token, así que la
// descarga no puede ir por una ruta auth:sanctum — ver ApkVersionController::vigente().
Route::get('apk-updates/{apkVersion}/archivo', [ApkVersionController::class, 'descargarFirmado'])
    ->middleware('signed')
    ->name('apk-updates.descargar');

// El teléfono debe poder actualizarse incluso antes de iniciar sesión.
Route::get('actualizaciones-apk/vigente', [ApkVersionController::class, 'vigente'])->name('actualizaciones-apk.vigente');

// ===== PROTECTED ROUTES (requieren autenticación) =====
// EnsureIdempotency va después de auth:sanctum para poder acotar la clave al
// usuario autenticado; solo actúa si la petición trae el header Idempotency-Key.
Route::middleware(['auth:sanctum', EnsurePerfilAcceso::class, EnsureIdempotency::class])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('perfiles-acceso', PerfilAccesoController::class)
        ->parameters(['perfiles-acceso' => 'perfilAcceso'])->only(['index', 'store', 'show', 'update']);

    Route::apiResource('empresas', EmpresaController::class);
    Route::apiResource('agencias', AgenciaController::class);
    Route::get('usuarios/consultar-dni/{dni}', [UserController::class, 'consultarDni'])->name('usuarios.consultar-dni');
    Route::get('usuarios/roles-asignables', [UserController::class, 'rolesAsignables'])->name('usuarios.roles-asignables');
    Route::get('usuarios/{user}/modulos', [UsuarioModuloController::class, 'show'])->name('usuarios.modulos.show');
    Route::put('usuarios/{user}/modulos', [UsuarioModuloController::class, 'update'])->name('usuarios.modulos.update');
    Route::post('usuarios/{user}/impersonar', [ImpersonacionController::class, 'iniciar'])->name('usuarios.impersonar');
    Route::post('impersonaciones/finalizar', [ImpersonacionController::class, 'finalizar'])->name('impersonaciones.finalizar');
    Route::apiResource('usuarios', UserController::class)->parameters(['usuarios' => 'user']);
    Route::post('usuarios/fotocheck/pdf', [FotocheckController::class, 'pdf'])->name('usuarios.fotocheck.pdf');
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'update']);
    Route::apiResource('permisos', PermissionController::class)->only(['index']);
    Route::apiResource('modulos', ModuloController::class);

    // Gestión de actualizaciones APK — exclusiva del rol sistemas (ver
    // ApkVersionPolicy). La consulta pública de la versión vigente está arriba.
    Route::get('actualizaciones-apk', [ApkVersionController::class, 'index'])->name('actualizaciones-apk.index');
    Route::post('actualizaciones-apk', [ApkVersionController::class, 'store'])->name('actualizaciones-apk.store');
    Route::post('actualizaciones-apk/{apkVersion}/publicar', [ApkVersionController::class, 'publicar'])->name('actualizaciones-apk.publicar');
    Route::get('actualizaciones-apk/{apkVersion}/descargar', [ApkVersionController::class, 'descargar'])->name('actualizaciones-apk.descargar');
    Route::delete('actualizaciones-apk/{apkVersion}', [ApkVersionController::class, 'destroy'])->name('actualizaciones-apk.destroy');

    Route::get('gestion/permisos-temporales', [PermisoTemporalController::class, 'index'])->name('gestion.permisos-temporales.index');
    Route::post('gestion/permisos-temporales/{cliente}', [PermisoTemporalController::class, 'store'])->name('gestion.permisos-temporales.store');
    Route::delete('gestion/permisos-temporales/{permisoTemporal}', [PermisoTemporalController::class, 'destroy'])->name('gestion.permisos-temporales.destroy');
    Route::put('/configuracion', [ConfiguracionSistemaController::class, 'update'])->name('configuracion.update');

    Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
    Route::post('notificaciones/{notificacion}/marcar-leido', [NotificacionController::class, 'marcarLeido'])->name('notificaciones.marcar-leido');
    Route::post('notificaciones/marcar-todas-leidas', [NotificacionController::class, 'marcarTodasLeidas'])->name('notificaciones.marcar-todas-leidas');

    Route::get('dashboard/resumen', [DashboardController::class, 'resumen'])->name('dashboard.resumen');
    Route::get('dashboard/linea-de-tiempo-atrasos', [DashboardController::class, 'lineaDeTiempoAtrasos'])->name('dashboard.linea-de-tiempo-atrasos');
    Route::get('dashboard/grafico-cobranza-desembolso', [DashboardController::class, 'graficoCobranzaDesembolso'])->name('dashboard.grafico-cobranza-desembolso');
    Route::get('dashboard/cartera-activa-por-tipo', [DashboardController::class, 'carteraActivaPorTipo'])->name('dashboard.cartera-activa-por-tipo');
    Route::get('dashboard/produccion-por-asesor', [DashboardController::class, 'produccionPorAsesor'])->name('dashboard.produccion-por-asesor');
    Route::get('dashboard/avance-cobranza-hoy', [DashboardController::class, 'avanceCobranzaHoy'])->name('dashboard.avance-cobranza-hoy');
    Route::get('dashboard/mapa-clientes', [DashboardController::class, 'mapaClientes'])->name('dashboard.mapa-clientes');
    Route::get('dashboard/mapa-unificado', [DashboardController::class, 'mapaUnificado'])->name('dashboard.mapa-unificado');

    Route::prefix('qr')->middleware('can:gestionar-qr')->group(function () {
        Route::get('generar', [QrController::class, 'generar'])->name('qr.generar');
        Route::get('/', [QrController::class, 'index'])->name('qr.index');
        Route::post('/', [QrController::class, 'store'])->name('qr.store');
        Route::get('{qrGenerado}/png', [QrController::class, 'png'])->whereNumber('qrGenerado')->name('qr.png');
        Route::delete('{qrGenerado}', [QrController::class, 'destroy'])->whereNumber('qrGenerado')->name('qr.destroy');
    });

    Route::get('ubigeo/departamentos', [UbigeoController::class, 'departamentos'])->name('ubigeo.departamentos');
    Route::get('ubigeo/departamentos/{departamento}/provincias', [UbigeoController::class, 'provincias'])->name('ubigeo.provincias');
    Route::get('ubigeo/provincias/{provincia}/distritos', [UbigeoController::class, 'distritos'])->name('ubigeo.distritos');
    Route::get('ubigeo/distritos/{distrito}', [UbigeoController::class, 'distrito'])->name('ubigeo.distrito');

    Route::get('clientes/asesores', [ClienteController::class, 'asesoresParaAsignar'])->name('clientes.asesores');
    Route::get('opciones/clientes', [ClienteController::class, 'opciones'])->name('opciones.clientes');
    Route::get('opciones/clientes/{cliente}', [ClienteController::class, 'opcion'])->name('opciones.clientes.show');
    Route::apiResource('clientes', ClienteController::class);
    Route::post('clientes/{cliente}/asignar', [ClienteController::class, 'asignar'])->name('clientes.asignar');
    Route::get('clientes/consultar-dni/{dni}', [ClienteController::class, 'consultarDni'])->name('clientes.consultar-dni');

    Route::get('clientes/consultar-ruc/{ruc}', [ClienteController::class, 'consultarRuc'])->name('clientes.consultar-ruc');
    Route::get('clientes/{cliente}/ficha-socioeconomica', [FichaSocioeconomicaController::class, 'show'])->name('clientes.ficha-socioeconomica.show');
    Route::put('clientes/{cliente}/ficha-socioeconomica', [FichaSocioeconomicaController::class, 'update'])->name('clientes.ficha-socioeconomica.update');
    Route::delete('clientes/{cliente}/fotos/{foto}', [ClienteController::class, 'eliminarFoto'])->name('clientes.fotos.destroy');

    Route::get('caja', [CajaController::class, 'miCaja'])->name('caja.mia');
    Route::post('caja/aperturar', [CajaController::class, 'aperturar'])->name('caja.aperturar');
    Route::post('caja/cerrar', [CajaController::class, 'cerrar'])->name('caja.cerrar');
    Route::get('caja/cierre/resumen', [CajaController::class, 'resumenCierre'])->name('caja.cierre.resumen');
    Route::get('caja/movimientos', [CajaController::class, 'movimientos'])->name('caja.movimientos.index');
    Route::get('caja/movimientos/usuarios', [CajaController::class, 'usuariosMovimientos'])->name('caja.movimientos.usuarios');
    Route::post('caja/movimientos', [CajaController::class, 'registrarMovimiento'])->name('caja.movimientos.registrar');
    Route::apiResource('cajas', CajaController::class)->only(['index', 'show']);
    Route::post('cajas/{caja}/cerrar-forzado', [CajaController::class, 'cerrarForzado'])->name('cajas.cerrar-forzado');
    Route::post('cajas/{caja}/reabrir', [CajaController::class, 'reabrir'])->name('cajas.reabrir');

    Route::get('bovedas/mia', [BovedaController::class, 'mia'])->name('bovedas.mia');
    Route::apiResource('bovedas', BovedaController::class)->only(['index', 'show']);
    Route::post('bovedas/{boveda}/cerrar', [BovedaController::class, 'cerrar'])->name('bovedas.cerrar');
    Route::get('bovedas/{boveda}/cierre/detalle', [BovedaController::class, 'detalleCierre'])->name('bovedas.cierre.detalle');
    Route::post('bovedas/{boveda}/cerrar-forzado', [BovedaController::class, 'cerrarForzado'])->name('bovedas.cerrar-forzado');
    Route::post('bovedas/{boveda}/aperturar', [BovedaController::class, 'aperturar'])->name('bovedas.aperturar');
    Route::post('bovedas/{boveda}/inyectar', [BovedaController::class, 'inyectar'])->name('bovedas.inyectar');
    Route::post('bovedas/{boveda}/retirar', [BovedaController::class, 'retirar'])->name('bovedas.retirar');
    Route::get('bovedas/{boveda}/inyecciones', [BovedaController::class, 'inyecciones'])->name('bovedas.inyecciones');
    Route::delete('bovedas/{boveda}/inyecciones/{movimiento}', [BovedaController::class, 'eliminarInyeccion'])->name('bovedas.inyecciones.eliminar');
    Route::post('bovedas/{boveda}/reabrir', [BovedaController::class, 'reabrir'])->name('bovedas.reabrir');

    Route::apiResource('bancos', BancoController::class);

    Route::get('bovedas/{boveda}/cuentas-bancarias', [CuentaBancariaController::class, 'index'])->name('bovedas.cuentas-bancarias.index');
    Route::post('bovedas/{boveda}/cuentas-bancarias', [CuentaBancariaController::class, 'store'])->name('bovedas.cuentas-bancarias.store');
    Route::get('cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'show'])->name('cuentas-bancarias.show');
    Route::put('cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'update'])->name('cuentas-bancarias.update');
    Route::delete('cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'destroy'])->name('cuentas-bancarias.destroy');
    Route::post('cuentas-bancarias/{cuentaBancaria}/movimiento', [CuentaBancariaController::class, 'movimiento'])->name('cuentas-bancarias.movimiento');
    Route::get('cuentas-bancarias/{cuentaBancaria}/movimientos', [CuentaBancariaController::class, 'movimientos'])->name('cuentas-bancarias.movimientos');
    Route::post('cuentas-bancarias/{cuentaBancaria}/conciliar', [CuentaBancariaController::class, 'conciliar'])->name('cuentas-bancarias.conciliar');
    Route::get('cuentas-bancarias/{cuentaBancaria}/conciliaciones', [CuentaBancariaController::class, 'conciliaciones'])->name('cuentas-bancarias.conciliaciones');

    Route::apiResource('billetajes', BilletajeController::class)->only(['index', 'store']);
    Route::post('billetajes/{billetaje}/aceptar', [BilletajeController::class, 'aceptar'])->name('billetajes.aceptar');
    Route::post('billetajes/{billetaje}/rechazar', [BilletajeController::class, 'rechazar'])->name('billetajes.rechazar');

    Route::apiResource('conceptos', ConceptoController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('bienes', BienController::class)->only(['index', 'store', 'show', 'update'])->parameters(['bienes' => 'bien']);

    Route::apiResource('vehiculos', VehiculoController::class)->only(['index', 'store', 'show', 'update'])->parameters(['vehiculos' => 'vehiculo']);
    Route::post('creditos-vehiculares', [CreditoVehicularController::class, 'store'])->name('creditos-vehiculares.store');

    Route::apiResource('inmuebles', InmuebleController::class)->only(['index', 'store', 'show', 'update'])->parameters(['inmuebles' => 'inmueble']);
    Route::post('creditos-hipotecarios', [CreditoHipotecarioController::class, 'store'])->name('creditos-hipotecarios.store');

    Route::post('creditos-diarios', [CreditoDiarioController::class, 'store'])->name('creditos-diarios.store');

    Route::get('creditos-prendarios/supervisores', [CreditoController::class, 'supervisores'])->name('creditos-prendarios.supervisores');
    Route::get('creditos-prendarios/configuracion', [CreditoController::class, 'configuracion'])->name('creditos-prendarios.configuracion');
    Route::post('creditos-prendarios/cronograma-preview', [CreditoController::class, 'cronogramaPreview'])->name('creditos-prendarios.cronograma-preview');
    Route::apiResource('creditos-prendarios', CreditoController::class)->only(['index', 'store', 'show', 'destroy'])->parameters(['creditos-prendarios' => 'credito']);
    Route::post('creditos-prendarios/{credito}/aprobar', [CreditoController::class, 'aprobar'])->name('creditos-prendarios.aprobar');
    Route::post('creditos-prendarios/{credito}/rechazar', [CreditoController::class, 'rechazar'])->name('creditos-prendarios.rechazar');
    Route::post('creditos-prendarios/{credito}/subsanar', [CreditoController::class, 'subsanar'])->name('creditos-prendarios.subsanar');
    Route::post('creditos-prendarios/{credito}/desembolsar', [CreditoController::class, 'desembolsar'])->name('creditos-prendarios.desembolsar');
    Route::get('creditos-prendarios/{credito}/evidencia-desembolso', [CreditoDiarioDesembolsoEvidenciaController::class, 'show'])->name('creditos-prendarios.evidencia-desembolso.show');
    Route::post('creditos-prendarios/{credito}/evidencia-desembolso', [CreditoDiarioDesembolsoEvidenciaController::class, 'store'])->name('creditos-prendarios.evidencia-desembolso.store');
    Route::post('creditos-prendarios/{credito}/refrendar', [CreditoController::class, 'refrendar'])->name('creditos-prendarios.refrendar');
    Route::post('creditos-prendarios/{credito}/pagar-cuota', [CreditoController::class, 'pagarCuota'])->name('creditos-prendarios.pagar-cuota');
    Route::post('creditos-prendarios/{credito}/pagar-cuotas-preview', [CreditoController::class, 'pagarCuotasPreview'])->name('creditos-prendarios.pagar-cuotas-preview');
    Route::post('creditos-prendarios/{credito}/pagar-cuotas', [CreditoController::class, 'pagarCuotas'])->name('creditos-prendarios.pagar-cuotas');
    Route::post('creditos-prendarios/{credito}/regularizar', [CreditoController::class, 'regularizar'])->name('creditos-prendarios.regularizar');
    Route::get('regularizaciones-credito', [CreditoController::class, 'regularizaciones'])->name('regularizaciones-credito.index');
    Route::delete('regularizaciones-credito/{regularizacion}', [CreditoController::class, 'eliminarRegularizacion'])->name('regularizaciones-credito.destroy');
    Route::post('creditos-prendarios/{credito}/liquidar', [CreditoController::class, 'liquidar'])->name('creditos-prendarios.liquidar');
    Route::post('creditos-prendarios/{credito}/adendar', [CreditoController::class, 'adendar'])->name('creditos-prendarios.adendar');
    Route::post('creditos-prendarios/{credito}/refinanciar', [CreditoController::class, 'refinanciar'])->name('creditos-prendarios.refinanciar');
    Route::post('creditos-prendarios/{credito}/actualizar-interes', [CreditoController::class, 'actualizarInteres'])->name('creditos-prendarios.actualizar-interes');
    Route::post('creditos-prendarios/{credito}/actualizar-condiciones', [CreditoController::class, 'actualizarCondiciones'])->name('creditos-prendarios.actualizar-condiciones');
    Route::post('creditos-prendarios/{credito}/actualizar-fecha-desembolso', [CreditoController::class, 'actualizarFechaDesembolso'])->name('creditos-prendarios.actualizar-fecha-desembolso');
    Route::get('creditos-prendarios/{credito}/asesores-registrado-por', [CreditoController::class, 'asesoresRegistradoPor'])->name('creditos-prendarios.asesores-registrado-por');
    Route::post('creditos-prendarios/{credito}/cambiar-registrado-por', [CreditoController::class, 'cambiarRegistradoPor'])->name('creditos-prendarios.cambiar-registrado-por');
    Route::post('creditos-prendarios/{credito}/actualizar-numero-cuotas', [CreditoController::class, 'actualizarNumeroCuotas'])->name('creditos-prendarios.actualizar-numero-cuotas');
    Route::post('creditos-prendarios/{credito}/revertir-aprobacion', [CreditoController::class, 'revertirAprobacion'])->name('creditos-prendarios.revertir-aprobacion');
    Route::post('creditos-prendarios/{credito}/enviar-tienda', [CreditoController::class, 'enviarATienda'])->name('creditos-prendarios.enviar-tienda');
    Route::post('creditos-prendarios/{credito}/conformidad', [CreditoController::class, 'confirmarConformidad'])->name('creditos-prendarios.conformidad');
    Route::post('creditos-prendarios/{credito}/vender', [CreditoController::class, 'vender'])->name('creditos-prendarios.vender');
    Route::get('creditos-prendarios/{credito}/cronograma/ver', [CreditoController::class, 'verCronograma'])->name('creditos-prendarios.cronograma.ver');
    Route::get('creditos-prendarios/{credito}/expediente', [CreditoExpedienteController::class, 'index'])->name('creditos-prendarios.expediente.index');
    Route::post('creditos-prendarios/{credito}/expediente', [CreditoExpedienteController::class, 'store'])->name('creditos-prendarios.expediente.store');
    Route::delete('creditos-prendarios/{credito}/expediente/{documento}', [CreditoExpedienteController::class, 'destroy'])->name('creditos-prendarios.expediente.destroy');
    Route::get('creditos-prendarios/{credito}/documentos/{documento}/ver', [CreditoController::class, 'verDocumento'])->name('creditos-prendarios.documentos.ver');
    Route::post('creditos-prendarios/{credito}/documentos/{documento}/marcar-impreso', [CreditoController::class, 'marcarImpreso'])->name('creditos-prendarios.documentos.marcar-impreso');
    Route::post('creditos-prendarios/{credito}/documentos/{documento}/subir-firmado', [CreditoController::class, 'subirDocumentoFirmado'])->name('creditos-prendarios.documentos.subir-firmado');

    Route::get('configuraciones-credito-prendario', [ConfiguracionCreditoController::class, 'index'])->name('configuraciones-credito-prendario.index');
    Route::put('configuraciones-credito-prendario', [ConfiguracionCreditoController::class, 'update'])->name('configuraciones-credito-prendario.update');
    Route::delete('configuraciones-credito-prendario/{configuracion}', [ConfiguracionCreditoController::class, 'destroy'])->name('configuraciones-credito-prendario.destroy');

    Route::get('configuraciones-venta', [ConfiguracionVentaController::class, 'index'])->name('configuraciones-venta.index');
    Route::put('configuraciones-venta', [ConfiguracionVentaController::class, 'update'])->name('configuraciones-venta.update');
    Route::delete('configuraciones-venta/{configuracion}', [ConfiguracionVentaController::class, 'destroy'])->name('configuraciones-venta.destroy');

    Route::get('ventas/catalogo', [CatalogoVentaController::class, 'index'])->name('ventas.catalogo');
    Route::get('ventas', [VentaController::class, 'index'])->name('ventas.index');
    Route::post('ventas', [VentaController::class, 'store'])->name('ventas.store');
    Route::get('ventas/{venta}', [VentaController::class, 'show'])->name('ventas.show');
    Route::post('ventas/{venta}/cuotas/{cuota}/pagar', [VentaController::class, 'pagarCuota'])->name('ventas.cuotas.pagar');
    Route::post('ventas/{venta}/abonar', [VentaController::class, 'abonar'])->name('ventas.abonar');
    Route::post('ventas/{venta}/cancelar', [VentaController::class, 'cancelar'])->name('ventas.cancelar');
    Route::get('ventas/{venta}/documentos/{documento}', [VentaController::class, 'documento'])->name('ventas.documentos.ver');
    Route::get('ventas/{venta}/cronograma/ver', [VentaController::class, 'verCronograma'])->name('ventas.cronograma.ver');

    // Facturación electrónica
    Route::get('facturacion/creditos-pendientes', [ComprobanteController::class, 'pendientes'])->name('facturacion.creditos-pendientes');
    Route::get('facturacion/creditos/{credito}/previsualizar', [ComprobanteController::class, 'previsualizar'])->name('facturacion.creditos.previsualizar');
    Route::post('facturacion/creditos/{credito}/emitir', [ComprobanteController::class, 'emitir'])->name('facturacion.creditos.emitir');
    Route::get('facturacion/comprobantes', [ComprobanteController::class, 'index'])->name('facturacion.comprobantes.index');
    Route::get('facturacion/comprobantes/{comprobante}', [ComprobanteController::class, 'show'])->name('facturacion.comprobantes.show');
    Route::post('facturacion/comprobantes/{comprobante}/anular', [ComprobanteController::class, 'anular'])->name('facturacion.comprobantes.anular'); // Anulación LOCAL (sin envío SUNAT)
    Route::post('facturacion/comprobantes/{comprobante}/comunicar-baja', [ComprobanteController::class, 'comunicarBaja'])->name('facturacion.comprobantes.baja'); // Comunicación de BAJA a SUNAT
    Route::post('facturacion/comprobantes/{comprobante}/nota-credito', [ComprobanteController::class, 'emitirNotaCredito'])->name('facturacion.comprobantes.nota-credito'); // Nota de crédito (07)
    Route::post('facturacion/comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar'])->name('facturacion.comprobantes.reenviar'); // Reintento manual
    Route::get('facturacion/comprobantes/{comprobante}/cdr', [ComprobanteController::class, 'consultarCdr'])->name('facturacion.comprobantes.cdr'); // Consultar CDR
    Route::get('facturacion/comprobantes/{comprobante}/pdf', [ComprobanteController::class, 'verPdf'])->name('facturacion.comprobantes.pdf');

    // Configuración de facturación
    Route::get('configuracion-facturacion', [ConfiguracionFacturacionController::class, 'show'])->name('configuracion-facturacion.show');
    Route::put('configuracion-facturacion', [ConfiguracionFacturacionController::class, 'update'])->name('configuracion-facturacion.update');

    // Series de comprobantes
    Route::get('facturacion/series', [SerieComprobanteController::class, 'index'])->name('facturacion.series.index');
    Route::post('facturacion/series', [SerieComprobanteController::class, 'store'])->name('facturacion.series.store');
    Route::put('facturacion/series/{serie}', [SerieComprobanteController::class, 'update'])->name('facturacion.series.update');
    Route::delete('facturacion/series/{serie}', [SerieComprobanteController::class, 'destroy'])->name('facturacion.series.destroy');

    // Reportes de facturación
    Route::prefix('facturacion/reportes')->group(function () {
        Route::get('resumen', [ReporteFacturacionController::class, 'resumen'])->name('facturacion.reportes.resumen');
        Route::get('emitidas', [ReporteFacturacionController::class, 'emitidas'])->name('facturacion.reportes.emitidas');
        Route::get('por-dia', [ReporteFacturacionController::class, 'porDia'])->name('facturacion.reportes.por-dia');
        Route::get('por-estado', [ReporteFacturacionController::class, 'porEstado'])->name('facturacion.reportes.por-estado');
        Route::get('por-serie', [ReporteFacturacionController::class, 'porSerie'])->name('facturacion.reportes.por-serie');
        Route::get('por-cliente', [ReporteFacturacionController::class, 'porCliente'])->name('facturacion.reportes.por-cliente');
        Route::get('anulados', [ReporteFacturacionController::class, 'anulados'])->name('facturacion.reportes.anulados');
        Route::get('notas-credito', [ReporteFacturacionController::class, 'notasCredito'])->name('facturacion.reportes.notas-credito');
        Route::get('exportar-excel', [ReporteFacturacionController::class, 'exportExcel'])->name('facturacion.reportes.exportar-excel');
        Route::get('dashboard', [ReporteFacturacionController::class, 'dashboard'])->name('facturacion.reportes.dashboard');
    });

    // Credenciales SUNAT (gestión desde módulo Empresa)
    Route::prefix('empresas/{empresa}/credenciales-sunat')->group(function () {
        Route::get('/', [CredencialesSunatController::class, 'show'])->name('empresas.credenciales-sunat.show');
        Route::post('/', [CredencialesSunatController::class, 'store'])->name('empresas.credenciales-sunat.store');
        Route::post('/validar', [CredencialesSunatController::class, 'validar'])->name('empresas.credenciales-sunat.validar');
        Route::delete('/', [CredencialesSunatController::class, 'destroy'])->name('empresas.credenciales-sunat.destroy');
        Route::get('/certificado', [CredencialesSunatController::class, 'descargarCertificado'])->name('empresas.credenciales-sunat.certificado');
    });

    Route::get('tienda-solicitudes', [InteresArticuloAdminController::class, 'index'])->name('tienda-solicitudes.index');
    Route::post('tienda-solicitudes/{interes}/atender', [InteresArticuloAdminController::class, 'atender'])->name('tienda-solicitudes.atender');
    Route::delete('tienda-solicitudes/{interes}', [InteresArticuloAdminController::class, 'destroy'])->name('tienda-solicitudes.destroy');

    Route::get('tienda-productos', [TiendaProductoController::class, 'index'])->name('tienda-productos.index');
    Route::patch('tienda-productos/{tipo}/{id}', [TiendaProductoController::class, 'update'])->name('tienda-productos.update')->whereIn('tipo', ['bien', 'vehiculo', 'inmueble'])->whereNumber('id');
    Route::post('tienda-productos/{tipo}/{id}/retirar', [TiendaProductoController::class, 'retirar'])->name('tienda-productos.retirar')->whereIn('tipo', ['bien', 'vehiculo', 'inmueble'])->whereNumber('id');

    Route::get('simulaciones-credito/configuracion', [SimuladorController::class, 'configuracion'])->name('simulaciones-credito.configuracion');
    Route::post('simulaciones-credito/calcular', [SimuladorController::class, 'calcular'])->name('simulaciones-credito.calcular');
    Route::apiResource('simulaciones-credito', SimuladorController::class)->only(['index', 'store', 'show', 'destroy'])->parameters(['simulaciones-credito' => 'simulacion']);

    Route::get('cobros', [CobroController::class, 'index'])->name('cobros.index');
    Route::get('cobros/resumen', [CobroController::class, 'resumen'])->name('cobros.resumen');
    Route::get('cobros/pdf', [CobroController::class, 'pdf'])->name('cobros.pdf');
    Route::get('cobros/excel', [CobroController::class, 'excel'])->name('cobros.excel');
    Route::get('cobros/creditos-pendientes/{cliente}', [CobroController::class, 'creditosPendientes'])->name('cobros.creditos-pendientes');
    Route::get('cobros/{cobro}/voucher', [CobroController::class, 'voucher'])->name('cobros.voucher');
    Route::get('cobros/{cobro}/voucher/texto', [CobroController::class, 'voucherTexto'])->name('cobros.voucher.texto');
    Route::post('cobros/{cobro}/anular', [CobroController::class, 'anular'])->name('cobros.anular');

    Route::get('reportes/movimientos-dinero', [ReporteMovimientosController::class, 'movimientosDinero'])->name('reportes.movimientos-dinero');
    Route::get('reportes/movimientos-dinero/pdf', [ReporteMovimientosController::class, 'movimientosDineroPdf'])->name('reportes.movimientos-dinero.pdf');
    Route::get('reportes/movimientos-dinero/excel', [ReporteMovimientosController::class, 'movimientosDineroExcel'])->name('reportes.movimientos-dinero.excel');
    Route::get('reportes/cobranza-diaria', [ReporteCobranzaController::class, 'cobranzaDiaria'])->name('reportes.cobranza-diaria');
    Route::get('reportes/cobranza-diaria/pdf', [ReporteCobranzaController::class, 'cobranzaDiariaPdf'])->name('reportes.cobranza-diaria.pdf');
    Route::get('reportes/cobranza-diaria/excel', [ReporteCobranzaController::class, 'cobranzaDiariaExcel'])->name('reportes.cobranza-diaria.excel');
    Route::get('reportes/atrasos-diarios', [ReporteAtrasosController::class, 'atrasos'])->name('reportes.atrasos-diarios');
    Route::get('reportes/atrasos-diarios/pdf', [ReporteAtrasosController::class, 'atrasosPdf'])->name('reportes.atrasos-diarios.pdf');
    Route::get('reportes/atrasos-diarios/excel', [ReporteAtrasosController::class, 'atrasosExcel'])->name('reportes.atrasos-diarios.excel');
    Route::get('reportes/cobranza-mensual', [ReporteCobranzaMensualController::class, 'cobranzaMensual'])->name('reportes.cobranza-mensual');
    Route::get('reportes/cobranza-mensual/anual', [ReporteCobranzaMensualController::class, 'cobranzaAnual'])->name('reportes.cobranza-mensual.anual');
    Route::get('reportes/cajas-apertura-cierre', [ReporteCajasController::class, 'aperturasCierres'])->name('reportes.cajas-apertura-cierre');
    Route::get('reportes/cajas-apertura-cierre/{ciclo}/reporte', [ReporteCajasController::class, 'reporteCierre'])->name('reportes.cajas-apertura-cierre.reporte');
    Route::get('reportes/cajas-apertura-cierre/{ciclo}/reporte/pdf', [ReporteCajasController::class, 'reporteCierrePdf'])->name('reportes.cajas-apertura-cierre.reporte.pdf');
    Route::get('reportes/cajas-apertura-cierre/{ciclo}/detalle', [ReporteCajasController::class, 'detalle'])->name('reportes.cajas-apertura-cierre.detalle');
    Route::get('reportes/cajas-apertura-cierre/pdf', [ReporteCajasController::class, 'aperturasCierresPdf'])->name('reportes.cajas-apertura-cierre.pdf');
    Route::get('reportes/cajas-apertura-cierre/excel', [ReporteCajasController::class, 'aperturasCierresExcel'])->name('reportes.cajas-apertura-cierre.excel');
    Route::get('reportes/flujo-caja', [ReporteFlujoCajaController::class, 'flujoCaja'])->name('reportes.flujo-caja');
    Route::get('reportes/flujo-caja/anual', [ReporteFlujoCajaController::class, 'flujoCajaAnual'])->name('reportes.flujo-caja.anual');
    Route::get('reportes/flujo-caja/mensual', [ReporteFlujoCajaController::class, 'flujoCajaMensual'])->name('reportes.flujo-caja.mensual');

    Route::get('reportes/ingresos-egresos', [ReporteIngresosEgresosController::class, 'ingresosEgresos'])->name('reportes.ingresos-egresos');
    Route::get('reportes/ingresos-egresos/pdf', [ReporteIngresosEgresosController::class, 'ingresosEgresosPdf'])->name('reportes.ingresos-egresos.pdf');
    Route::get('reportes/ingresos-egresos/excel', [ReporteIngresosEgresosController::class, 'ingresosEgresosExcel'])->name('reportes.ingresos-egresos.excel');

    Route::get('reportes/planilla', [ReportePlanillaController::class, 'index'])->name('reportes.planilla');
    Route::get('reportes/planilla/{planilla}', [ReportePlanillaController::class, 'planilla'])->name('reportes.planilla.detalle');
    Route::get('reportes/planilla/{planilla}/pdf', [ReportePlanillaController::class, 'planillaPdf'])->name('reportes.planilla.pdf');
    Route::get('reportes/planilla/{planilla}/excel', [ReportePlanillaController::class, 'planillaExcel'])->name('reportes.planilla.excel');

    Route::get('planillas', [PlanillaController::class, 'index'])->name('planillas.index');
    Route::post('planillas', [PlanillaController::class, 'store'])->name('planillas.store');
    Route::get('planillas/{planilla}', [PlanillaController::class, 'show'])->name('planillas.show');
    Route::post('planillas/{planilla}/lineas', [PlanillaController::class, 'lineas'])->name('planillas.lineas');
    Route::post('planillas/{planilla}/cerrar', [PlanillaController::class, 'cerrar'])->name('planillas.cerrar');
    Route::post('planillas/{planilla}/reabrir', [PlanillaController::class, 'reabrir'])->name('planillas.reabrir');

    Route::get('planilla-descuentos', [PlanillaDescuentoController::class, 'index'])->name('planilla-descuentos.index');
    Route::post('planilla-descuentos', [PlanillaDescuentoController::class, 'store'])->name('planilla-descuentos.store');
    Route::post('planilla-descuentos/{descuento}/anular', [PlanillaDescuentoController::class, 'anular'])->name('planilla-descuentos.anular');

    Route::get('reportes/cumpleanos', [ReporteCumpleanosController::class, 'cumpleanos'])->name('reportes.cumpleanos');
    Route::get('reportes/cumpleanos/pdf', [ReporteCumpleanosController::class, 'cumpleanosPdf'])->name('reportes.cumpleanos.pdf');
    Route::get('reportes/cumpleanos/excel', [ReporteCumpleanosController::class, 'cumpleanosExcel'])->name('reportes.cumpleanos.excel');

    Route::get('rutas-cobranza/asesores', [RutaCobranzaController::class, 'asesores'])->name('rutas-cobranza.asesores');
    Route::get('rutas-cobranza', [RutaCobranzaController::class, 'show'])->name('rutas-cobranza.show');
    Route::get('rutas-cobranza/trazado', [RutaCobranzaController::class, 'trazado'])->name('rutas-cobranza.trazado');
    Route::post('rutas-cobranza/reordenar', [RutaCobranzaController::class, 'reordenar'])->name('rutas-cobranza.reordenar');
    Route::post('rutas-cobranza/clientes/{cliente}/clonar', [RutaCobranzaController::class, 'clonar'])->name('rutas-cobranza.clonar');
    Route::delete('rutas-cobranza/clientes/{cliente}', [RutaCobranzaController::class, 'quitar'])->name('rutas-cobranza.quitar');

    Route::post('ubicaciones-asesores', [UbicacionAsesorController::class, 'store'])->middleware('throttle:30,1')->name('ubicaciones-asesores.store');
    Route::get('ubicaciones-asesores', [UbicacionAsesorController::class, 'index'])->name('ubicaciones-asesores.index');
    Route::get('ubicaciones-asesores/asesores-visibles', [UbicacionAsesorController::class, 'asesoresVisibles'])->name('ubicaciones-asesores.asesores-visibles');
    Route::get('ubicaciones-asesores/historial', [UbicacionAsesorController::class, 'historial'])->name('ubicaciones-asesores.historial');
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
