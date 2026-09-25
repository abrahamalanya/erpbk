<?php

namespace Database\Seeders;

use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Models\Permission;
use App\Modules\Sistemas\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        'empresas.ver', 'empresas.editar',
        'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar',
        'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar',
        'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar', 'clientes.asignar',
        'gestion.permisos_temporales',
        'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'cajas.reabrir',
        'bovedas.ver', 'bovedas.cerrar', 'bovedas.aperturar', 'bovedas.inyectar', 'bovedas.retirar', 'bovedas.reabrir',
        'cuentas_bancarias.ver', 'cuentas_bancarias.crear', 'cuentas_bancarias.editar', 'cuentas_bancarias.eliminar', 'cuentas_bancarias.movimiento', 'cuentas_bancarias.conciliar',
        'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar',
        'caja_movimientos.crear',
        'bienes.ver', 'bienes.crear', 'bienes.editar',
        'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.subsanar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'creditos_prendarios.editar', 'creditos_prendarios.eliminar', 'creditos_prendarios.revertir_aprobacion', 'creditos_prendarios.enviar_tienda', 'creditos_prendarios.vender',
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
        'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar',
        'ubicaciones_asesores.ver',
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
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'administrador_general' => ['empresas.ver', 'empresas.editar', 'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'cajas.reabrir', 'bovedas.ver', 'bovedas.cerrar', 'bovedas.aperturar', 'bovedas.inyectar', 'bovedas.retirar', 'bovedas.reabrir', 'cuentas_bancarias.ver', 'cuentas_bancarias.crear', 'cuentas_bancarias.editar', 'cuentas_bancarias.eliminar', 'cuentas_bancarias.movimiento', 'cuentas_bancarias.conciliar', 'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'creditos_prendarios.editar', 'creditos_prendarios.eliminar', 'creditos_prendarios.revertir_aprobacion', 'creditos_prendarios.enviar_tienda', 'creditos_prendarios.vender', 'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar', 'configuraciones_credito_prendario.eliminar', 'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.cancelar', 'configuraciones_venta.ver', 'configuraciones_venta.editar', 'configuraciones_venta.eliminar', 'intereses_tienda.ver', 'intereses_tienda.atender', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar', 'ubicaciones_asesores.ver', 'gestion.permisos_temporales'],
        'secretaria' => ['empresas.ver', 'agencias.ver', 'usuarios.ver', 'usuarios.crear', 'clientes.ver', 'clientes.crear', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.crear', 'caja_movimientos.crear', 'bienes.ver', 'creditos_prendarios.ver'],
        'administrador_agencia' => ['agencias.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'cajas.reabrir', 'bovedas.ver', 'bovedas.cerrar', 'bovedas.reabrir', 'cuentas_bancarias.ver', 'cuentas_bancarias.crear', 'cuentas_bancarias.editar', 'cuentas_bancarias.eliminar', 'cuentas_bancarias.movimiento', 'cuentas_bancarias.conciliar', 'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'creditos_prendarios.editar', 'creditos_prendarios.eliminar', 'creditos_prendarios.revertir_aprobacion', 'creditos_prendarios.enviar_tienda', 'creditos_prendarios.vender', 'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar', 'configuraciones_credito_prendario.eliminar', 'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'ventas.cancelar', 'configuraciones_venta.ver', 'configuraciones_venta.editar', 'configuraciones_venta.eliminar', 'intereses_tienda.ver', 'intereses_tienda.atender', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar', 'ubicaciones_asesores.ver'],
        'peinadora' => ['clientes.ver', 'clientes.crear', 'clientes.editar'],
        'supervisor' => ['clientes.ver', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.ver', 'billetajes.crear', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar', 'ubicaciones_asesores.ver'],
        'asesor' => ['clientes.ver', 'clientes.crear', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.ver', 'billetajes.crear', 'caja_movimientos.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.subsanar', 'creditos_prendarios.desembolsar', 'creditos_prendarios.refrendar', 'creditos_prendarios.pagar_cuota', 'creditos_prendarios.adendar', 'creditos_prendarios.refinanciar', 'creditos_prendarios.liquidar', 'ventas.ver', 'ventas.crear', 'ventas.cobrar', 'intereses_tienda.ver', 'intereses_tienda.atender', 'simulaciones_credito.ver', 'simulaciones_credito.crear', 'simulaciones_credito.eliminar'],
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
        'administrador_general' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas'],
        'administrador_agencia' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas'],
        'secretaria' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes'],
        'supervisor' => ['prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas'],
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
