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
use App\Modules\CreditoHipotecario\Http\Controllers\CreditoHipotecarioController;
use App\Modules\CreditoHipotecario\Http\Controllers\InmuebleController;
use App\Modules\CreditoPrendario\Http\Controllers\BienController;
use App\Modules\CreditoVehicular\Http\Controllers\CreditoVehicularController;
use App\Modules\CreditoVehicular\Http\Controllers\VehiculoController;
use App\Modules\Dashboard\Http\Controllers\DashboardController;
use App\Modules\Empresa\Http\Controllers\AgenciaController;
use App\Modules\Empresa\Http\Controllers\EmpresaController;
use App\Modules\Reportes\Http\Controllers\ReporteCajasController;
use App\Modules\Reportes\Http\Controllers\ReporteCobranzaController;
use App\Modules\Reportes\Http\Controllers\ReporteMovimientosController;
use App\Modules\Ruta\Http\Controllers\RutaCobranzaController;
use App\Modules\Simulador\Http\Controllers\SimuladorController;
use App\Modules\Sistemas\Http\Controllers\AuthController;
use App\Modules\Sistemas\Http\Controllers\ConceptoController;
use App\Modules\Sistemas\Http\Controllers\ConfiguracionSistemaController;
use App\Modules\Sistemas\Http\Controllers\ModuloController;
use App\Modules\Sistemas\Http\Controllers\NotificacionController;
use App\Modules\Sistemas\Http\Controllers\PermissionController;
use App\Modules\Sistemas\Http\Controllers\RoleController;
use App\Modules\Tienda\Http\Controllers\TiendaArticuloController;
use App\Modules\Tienda\Http\Controllers\TiendaController;
use App\Modules\Ubicacion\Http\Controllers\UbicacionAsesorController;
use App\Modules\Ubigeo\Http\Controllers\UbigeoController;
use App\Modules\Usuario\Http\Controllers\UserController;
use App\Modules\Usuario\Http\Controllers\UsuarioModuloController;
use App\Nucleo\Http\Controllers\BancoController;
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

// ===== PROTECTED ROUTES (requieren autenticación) =====
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('empresas', EmpresaController::class);
    Route::apiResource('agencias', AgenciaController::class);
    Route::get('usuarios/consultar-dni/{dni}', [UserController::class, 'consultarDni'])->name('usuarios.consultar-dni');
    Route::get('usuarios/roles-asignables', [UserController::class, 'rolesAsignables'])->name('usuarios.roles-asignables');
    Route::get('usuarios/{user}/modulos', [UsuarioModuloController::class, 'show'])->name('usuarios.modulos.show');
    Route::put('usuarios/{user}/modulos', [UsuarioModuloController::class, 'update'])->name('usuarios.modulos.update');
    Route::apiResource('usuarios', UserController::class)->parameters(['usuarios' => 'user']);
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'update']);
    Route::apiResource('permisos', PermissionController::class)->only(['index']);
    Route::apiResource('modulos', ModuloController::class)->only(['index']);
    Route::put('/configuracion', [ConfiguracionSistemaController::class, 'update'])->name('configuracion.update');

    Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
    Route::post('notificaciones/{notificacion}/marcar-leido', [NotificacionController::class, 'marcarLeido'])->name('notificaciones.marcar-leido');
    Route::post('notificaciones/marcar-todas-leidas', [NotificacionController::class, 'marcarTodasLeidas'])->name('notificaciones.marcar-todas-leidas');

    Route::get('dashboard/mapa-clientes', [DashboardController::class, 'mapaClientes'])->name('dashboard.mapa-clientes');

    Route::get('ubigeo/departamentos', [UbigeoController::class, 'departamentos'])->name('ubigeo.departamentos');
    Route::get('ubigeo/departamentos/{departamento}/provincias', [UbigeoController::class, 'provincias'])->name('ubigeo.provincias');
    Route::get('ubigeo/provincias/{provincia}/distritos', [UbigeoController::class, 'distritos'])->name('ubigeo.distritos');
    Route::get('ubigeo/distritos/{distrito}', [UbigeoController::class, 'distrito'])->name('ubigeo.distrito');

    Route::get('clientes/asesores', [ClienteController::class, 'asesoresParaAsignar'])->name('clientes.asesores');
    Route::apiResource('clientes', ClienteController::class);
    Route::post('clientes/{cliente}/asignar', [ClienteController::class, 'asignar'])->name('clientes.asignar');
    Route::get('clientes/consultar-dni/{dni}', [ClienteController::class, 'consultarDni'])->name('clientes.consultar-dni');
    Route::get('clientes/{cliente}/ficha-socioeconomica', [FichaSocioeconomicaController::class, 'show'])->name('clientes.ficha-socioeconomica.show');
    Route::put('clientes/{cliente}/ficha-socioeconomica', [FichaSocioeconomicaController::class, 'update'])->name('clientes.ficha-socioeconomica.update');

    Route::get('caja', [CajaController::class, 'miCaja'])->name('caja.mia');
    Route::post('caja/aperturar', [CajaController::class, 'aperturar'])->name('caja.aperturar');
    Route::post('caja/cerrar', [CajaController::class, 'cerrar'])->name('caja.cerrar');
    Route::get('caja/cierre/resumen', [CajaController::class, 'resumenCierre'])->name('caja.cierre.resumen');
    Route::get('caja/movimientos', [CajaController::class, 'movimientos'])->name('caja.movimientos.index');
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
    Route::post('billetajes/{billetaje}/aprobar', [BilletajeController::class, 'aprobar'])->name('billetajes.aprobar');
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
    Route::post('creditos-prendarios/{credito}/refrendar', [CreditoController::class, 'refrendar'])->name('creditos-prendarios.refrendar');
    Route::post('creditos-prendarios/{credito}/pagar-cuota', [CreditoController::class, 'pagarCuota'])->name('creditos-prendarios.pagar-cuota');
    Route::post('creditos-prendarios/{credito}/pagar-cuotas-preview', [CreditoController::class, 'pagarCuotasDiarioPreview'])->name('creditos-prendarios.pagar-cuotas-preview');
    Route::post('creditos-prendarios/{credito}/pagar-cuotas', [CreditoController::class, 'pagarCuotasDiario'])->name('creditos-prendarios.pagar-cuotas');
    Route::post('creditos-prendarios/{credito}/liquidar', [CreditoController::class, 'liquidar'])->name('creditos-prendarios.liquidar');
    Route::post('creditos-prendarios/{credito}/adendar', [CreditoController::class, 'adendar'])->name('creditos-prendarios.adendar');
    Route::post('creditos-prendarios/{credito}/refinanciar', [CreditoController::class, 'refinanciar'])->name('creditos-prendarios.refinanciar');
    Route::post('creditos-prendarios/{credito}/actualizar-interes', [CreditoController::class, 'actualizarInteres'])->name('creditos-prendarios.actualizar-interes');
    Route::post('creditos-prendarios/{credito}/actualizar-condiciones', [CreditoController::class, 'actualizarCondiciones'])->name('creditos-prendarios.actualizar-condiciones');
    Route::post('creditos-prendarios/{credito}/actualizar-fecha-desembolso', [CreditoController::class, 'actualizarFechaDesembolso'])->name('creditos-prendarios.actualizar-fecha-desembolso');
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

    Route::apiResource('simulaciones-credito', SimuladorController::class)->only(['index', 'store', 'show', 'destroy'])->parameters(['simulaciones-credito' => 'simulacion']);

    Route::get('cobros', [CobroController::class, 'index'])->name('cobros.index');
    Route::get('cobros/pdf', [CobroController::class, 'pdf'])->name('cobros.pdf');
    Route::get('cobros/excel', [CobroController::class, 'excel'])->name('cobros.excel');
    Route::get('cobros/creditos-pendientes/{cliente}', [CobroController::class, 'creditosPendientes'])->name('cobros.creditos-pendientes');
    Route::post('cobros/{cobro}/anular', [CobroController::class, 'anular'])->name('cobros.anular');

    Route::get('reportes/movimientos-dinero', [ReporteMovimientosController::class, 'movimientosDinero'])->name('reportes.movimientos-dinero');
    Route::get('reportes/movimientos-dinero/pdf', [ReporteMovimientosController::class, 'movimientosDineroPdf'])->name('reportes.movimientos-dinero.pdf');
    Route::get('reportes/movimientos-dinero/excel', [ReporteMovimientosController::class, 'movimientosDineroExcel'])->name('reportes.movimientos-dinero.excel');
    Route::get('reportes/cobranza-diaria', [ReporteCobranzaController::class, 'cobranzaDiaria'])->name('reportes.cobranza-diaria');
    Route::get('reportes/cobranza-diaria/pdf', [ReporteCobranzaController::class, 'cobranzaDiariaPdf'])->name('reportes.cobranza-diaria.pdf');
    Route::get('reportes/cobranza-diaria/excel', [ReporteCobranzaController::class, 'cobranzaDiariaExcel'])->name('reportes.cobranza-diaria.excel');
    Route::get('reportes/cajas-apertura-cierre', [ReporteCajasController::class, 'aperturasCierres'])->name('reportes.cajas-apertura-cierre');
    Route::get('reportes/cajas-apertura-cierre/{ciclo}/detalle', [ReporteCajasController::class, 'detalle'])->name('reportes.cajas-apertura-cierre.detalle');
    Route::get('reportes/cajas-apertura-cierre/pdf', [ReporteCajasController::class, 'aperturasCierresPdf'])->name('reportes.cajas-apertura-cierre.pdf');
    Route::get('reportes/cajas-apertura-cierre/excel', [ReporteCajasController::class, 'aperturasCierresExcel'])->name('reportes.cajas-apertura-cierre.excel');

    Route::get('rutas-cobranza/asesores', [RutaCobranzaController::class, 'asesores'])->name('rutas-cobranza.asesores');
    Route::get('rutas-cobranza', [RutaCobranzaController::class, 'show'])->name('rutas-cobranza.show');
    Route::post('rutas-cobranza/reordenar', [RutaCobranzaController::class, 'reordenar'])->name('rutas-cobranza.reordenar');

    Route::post('ubicaciones-asesores', [UbicacionAsesorController::class, 'store'])->middleware('throttle:30,1')->name('ubicaciones-asesores.store');
    Route::get('ubicaciones-asesores', [UbicacionAsesorController::class, 'index'])->name('ubicaciones-asesores.index');
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
