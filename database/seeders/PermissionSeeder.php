<?php

namespace Database\Seeders;

use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Models\Permission;
use App\Modules\Sistemas\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Catálogo completo de permisos. Es public para que los tests que cuentan
     * el total (ej. el listado de /api/permisos) no tengan que hardcodear un
     * número que se desactualiza cada vez que se agrega un permiso.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'empresas.ver', 'empresas.editar',
        'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar',
        'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'usuarios.impersonar',
        'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar', 'clientes.asignar',
        'gestion.permisos_temporales',
        'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'cajas.reabrir',
        'bovedas.ver', 'bovedas.cerrar', 'bovedas.aperturar', 'bovedas.inyectar', 'bovedas.retirar', 'bovedas.reabrir',
        'cuentas_bancarias.ver', 'cuentas_bancarias.crear', 'cuentas_bancarias.editar', 'cuentas_bancarias.eliminar', 'cuentas_bancarias.movimiento', 'cuentas_bancarias.conciliar',
        'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar',
        'caja_movimientos.crear',
        'bienes.ver', 'bienes.crear', 'bienes.editar',
        'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'creditos_prendarios.editar', 'creditos_prendarios.eliminar', 'creditos_prendarios.revertir_aprobacion', 'creditos_prendarios.enviar_tienda', 'creditos_prendarios.vender',
        'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar', 'configuraciones_credito_prendario.eliminar',
        'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.cancelar',
        'configuraciones_venta.ver', 'configuraciones_venta.editar', 'configuraciones_venta.eliminar',
        'intereses_tienda.ver', 'intereses_tienda.atender',
        'vehiculos.ver', 'vehiculos.crear', 'vehiculos.editar',
        'creditos_vehiculares.ver', 'creditos_vehiculares.crear',
        'inmuebles.ver', 'inmuebles.crear', 'inmuebles.editar',
        'creditos_hipotecarios.ver', 'creditos_hipotecarios.crear',
        'creditos_diarios.ver', 'creditos_diarios.crear',
        'cobranzas.ver', 'cobranzas.registrar',
        'dashboard.ver',
        'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar',
        'ubicaciones_asesores.ver',
        'planillas.ver', 'planillas.crear', 'planillas.editar', 'planillas.descuentos',
        'facturaciones.ver', 'facturaciones.crear', 'facturaciones.anular', 'facturaciones.emitir_nota_credito', 'facturaciones.descargar',
        'configuraciones_facturacion.ver', 'configuraciones_facturacion.editar',
    ];

    /**
     * Vehicular and hipotecario créditos reuse the prendario lifecycle
     * permissions (creditos_prendarios.*) since they run on the same engine
     * and endpoints; only garantía CRUD (vehiculos.* / inmuebles.*) and the
     * distinct entry action (creditos_vehiculares.* / creditos_hipotecarios.*)
     * are their own permissions. Roles that can register a bien/crédito
     * prendario get the equivalents.
     *
     * @var list<string>
     */
    private const GARANTIA_FORMAL_ROLES = ['administrador_general', 'administrador_agencia', 'supervisor', 'asesor'];

    /**
     * @var list<string>
     */
    private const GARANTIA_FORMAL_PERMISSIONS = [
        'vehiculos.ver', 'vehiculos.crear', 'vehiculos.editar', 'creditos_vehiculares.ver', 'creditos_vehiculares.crear',
        'inmuebles.ver', 'inmuebles.crear', 'inmuebles.editar', 'creditos_hipotecarios.ver', 'creditos_hipotecarios.crear',
        'creditos_diarios.ver', 'creditos_diarios.crear',
    ];

    /**
     * El módulo Cobranzas lo usan los mismos roles operativos que cobran
     * (refrendar / liquidar) más los administradores que supervisan; se
     * concede a todos ellos además de sus permisos base.
     *
     * @var list<string>
     */
    private const COBRANZAS_ROLES = ['administrador_general', 'administrador_agencia', 'supervisor', 'asesor'];

    /**
     * @var list<string>
     */
    private const COBRANZAS_PERMISSIONS = ['cobranzas.ver', 'cobranzas.registrar'];

    /**
     * La facturación electrónica la operan los mismos roles que ya cobran y
     * supervisan (que son quienes eligen a quién facturar) más los
     * administradores. `anular` y `emitir_nota_credito` quedan solo en
     * administrador_general porque tienen efecto fiscal y no se delegan: quien
     * puede ver el comprobante de todos no debería poder borrar su evidencia.
     * Los otros tres roles solo pueden `ver` y `descargar` lo emitido.
     *
     * @var list<string>
     */
    private const FACTURACION_PERMISSIONS = [
        'facturaciones.ver', 'facturaciones.descargar',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const FACTURACION_ROLES_PERMISOS = [
        'administrador_general' => [
            'facturaciones.ver', 'facturaciones.crear', 'facturaciones.anular',
            'facturaciones.emitir_nota_credito', 'facturaciones.descargar',
            'configuraciones_facturacion.ver', 'configuraciones_facturacion.editar',
        ],
        'administrador_agencia' => ['facturaciones.ver', 'facturaciones.crear', 'facturaciones.descargar'],
        'supervisor' => ['facturaciones.ver', 'facturaciones.crear', 'facturaciones.descargar'],
        'secretaria' => ['facturaciones.ver', 'facturaciones.descargar'],
    ];

    /**
     * El dashboard inicial resume cobranzas y desembolsos, así que va a los
     * mismos roles que el módulo Cobranzas: quien cobra o desembolsa ya puede
     * ver el resumen de su propia operación.
     *
     * @var list<string>
     */
    private const DASHBOARD_ROLES = ['administrador_general', 'administrador_agencia', 'supervisor', 'asesor'];

    /**
     * @var list<string>
     */
    private const DASHBOARD_PERMISSIONS = ['dashboard.ver'];

    /**
     * `usuarios.impersonar` ("ingresar como") solo va en `administrador_general`
     * — ni `secretaria` ni `administrador_agencia` lo reciben aunque ya
     * tengan `usuarios.editar`, porque el requisito de negocio es exclusivo
     * para `sistemas`/`administrador_general`. No "corregir" agregándolo a
     * los demás roles de gestión de usuarios.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'administrador_general' => ['empresas.ver', 'empresas.editar', 'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'usuarios.impersonar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'cajas.reabrir', 'bovedas.ver', 'bovedas.cerrar', 'bovedas.aperturar', 'bovedas.inyectar', 'bovedas.retirar', 'bovedas.reabrir', 'cuentas_bancarias.ver', 'cuentas_bancarias.crear', 'cuentas_bancarias.editar', 'cuentas_bancarias.eliminar', 'cuentas_bancarias.movimiento', 'cuentas_bancarias.conciliar', 'billetajes.ver', 'billetajes.crear', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'creditos_prendarios.editar', 'creditos_prendarios.eliminar', 'creditos_prendarios.revertir_aprobacion', 'creditos_prendarios.enviar_tienda', 'creditos_prendarios.vender', 'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar', 'configuraciones_credito_prendario.eliminar', 'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.cancelar', 'configuraciones_venta.ver', 'configuraciones_venta.editar', 'configuraciones_venta.eliminar', 'intereses_tienda.ver', 'intereses_tienda.atender', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar', 'ubicaciones_asesores.ver', 'gestion.permisos_temporales'],
        'secretaria' => ['empresas.ver', 'agencias.ver', 'usuarios.ver', 'usuarios.crear', 'clientes.ver', 'clientes.crear', 'cajas.aperturar', 'cajas.cerrar', 'caja_movimientos.crear', 'bienes.ver', 'creditos_prendarios.ver'],
        'administrador_agencia' => ['agencias.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'cajas.reabrir', 'bovedas.ver', 'bovedas.cerrar', 'bovedas.reabrir', 'cuentas_bancarias.ver', 'cuentas_bancarias.crear', 'cuentas_bancarias.editar', 'cuentas_bancarias.eliminar', 'cuentas_bancarias.movimiento', 'cuentas_bancarias.conciliar', 'billetajes.ver', 'billetajes.crear', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'creditos_prendarios.editar', 'creditos_prendarios.eliminar', 'creditos_prendarios.revertir_aprobacion', 'creditos_prendarios.enviar_tienda', 'creditos_prendarios.vender', 'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar', 'configuraciones_credito_prendario.eliminar', 'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.cancelar', 'configuraciones_venta.ver', 'configuraciones_venta.editar', 'configuraciones_venta.eliminar', 'intereses_tienda.ver', 'intereses_tienda.atender', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar', 'ubicaciones_asesores.ver'],
        'peinadora' => ['clientes.ver', 'clientes.crear', 'clientes.editar'],
        'supervisor' => ['clientes.ver', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.ver', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar', 'ubicaciones_asesores.ver'],
        'asesor' => ['clientes.ver', 'clientes.crear', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.ver', 'billetajes.aprobar', 'billetajes.rechazar', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'intereses_tienda.ver', 'intereses_tienda.atender', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar'],
    ];

    /**
     * Planilla: solo los roles que administran personal (los mismos que ya
     * manejan usuarios, cajas y bóvedas de toda la empresa). Ni asesor ni
     * supervisor entran — los sueldos y adelantos del personal no se muestran
     * al personal. Ver PlanillaPolicy.
     */
    private const PLANILLA_ROLES = ['administrador_general', 'administrador_agencia', 'secretaria'];

    private const PLANILLA_PERMISSIONS = [
        'planillas.ver', 'planillas.crear', 'planillas.editar', 'planillas.descuentos',
    ];

    /**
     * The app's módulo catalog (see ModuloService). Grows over time as more
     * business areas become toggleable per rol/usuario — not just créditos.
     *
     * @var list<array{key: string, nombre: string, grupo: string}>
     */
    private const MODULOS = [
        ['key' => 'prendario', 'nombre' => 'Créditos prendarios', 'grupo' => 'creditos'],
        ['key' => 'diario', 'nombre' => 'Créditos diarios', 'grupo' => 'creditos'],
        ['key' => 'hipotecario', 'nombre' => 'Créditos hipotecarios', 'grupo' => 'creditos'],
        ['key' => 'vehicular', 'nombre' => 'Créditos vehiculares', 'grupo' => 'creditos'],
        ['key' => 'solicitudes', 'nombre' => 'Solicitudes', 'grupo' => 'general'],
        ['key' => 'cajas', 'nombre' => 'Cajas', 'grupo' => 'general'],
        ['key' => 'planilla', 'nombre' => 'Planilla', 'grupo' => 'general'],
        ['key' => 'facturacion', 'nombre' => 'Facturación electrónica', 'grupo' => 'general'],
    ];

    /**
     * Default módulos per rol — editable afterwards from la pantalla de
     * Roles, pero se re-sincroniza en cada corrida de este seeder (igual que
     * ROLE_PERMISSIONS). 'sistemas' no necesita entrada: siempre tiene acceso
     * a todos los módulos (ver ModuloService::modulosEfectivos()).
     *
     * @var array<string, list<string>>
     */
    private const ROLE_MODULOS = [
        'administrador_general' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas', 'planilla', 'facturacion'],
        'administrador_agencia' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas', 'planilla', 'facturacion'],
        'secretaria' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'planilla', 'facturacion'],
        'supervisor' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas', 'facturacion'],
        'asesor' => ['prendario', 'diario', 'hipotecario', 'vehicular'],
        'peinadora' => [],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::where('name', 'sistemas')->firstOrFail()->syncPermissions(Permission::all());

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            if (in_array($roleName, self::GARANTIA_FORMAL_ROLES, true)) {
                $permissions = [...$permissions, ...self::GARANTIA_FORMAL_PERMISSIONS];
            } elseif ($roleName === 'secretaria') {
                $permissions = [...$permissions, 'vehiculos.ver', 'creditos_vehiculares.ver', 'inmuebles.ver', 'creditos_hipotecarios.ver'];
            }

            if (in_array($roleName, self::COBRANZAS_ROLES, true)) {
                $permissions = [...$permissions, ...self::COBRANZAS_PERMISSIONS];
            }

            if (in_array($roleName, self::DASHBOARD_ROLES, true)) {
                $permissions = [...$permissions, ...self::DASHBOARD_PERMISSIONS];
            }

            if (in_array($roleName, self::PLANILLA_ROLES, true)) {
                $permissions = [...$permissions, ...self::PLANILLA_PERMISSIONS];
            }

            // La facturación NO se reparte por lista de roles sino por mapa
            // explícito: cada rol recibe exactamente lo declarado arriba, sin
            // acumular el bloque genérico.
            if (isset(self::FACTURACION_ROLES_PERMISOS[$roleName])) {
                $permissions = [...$permissions, ...self::FACTURACION_ROLES_PERMISOS[$roleName]];
            } elseif (in_array($roleName, self::COBRANZAS_ROLES, true)) {
                $permissions = [...$permissions, ...self::FACTURACION_PERMISSIONS];
            }

            Role::where('name', $roleName)->firstOrFail()->syncPermissions($permissions);
        }

        $this->seedModulos();
    }

    private function seedModulos(): void
    {
        foreach (self::MODULOS as $modulo) {
            Modulo::query()->updateOrCreate(['key' => $modulo['key']], $modulo);
        }

        foreach (self::ROLE_MODULOS as $roleName => $keys) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $ids = Modulo::query()->whereIn('key', $keys)->pluck('id');

            $role->modulos()->sync($ids);
        }
    }
}
