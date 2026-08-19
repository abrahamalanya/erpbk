<?php

namespace Database\Seeders;

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
        'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado',
        'bovedas.ver', 'bovedas.cerrar',
        'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar',
        'bienes.ver', 'bienes.crear', 'bienes.editar',
        'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.firmar', 'creditos_prendarios.refrendar', 'creditos_prendarios.liquidar',
        'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'administrador_general' => ['empresas.ver', 'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'bovedas.ver', 'bovedas.cerrar', 'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.firmar', 'creditos_prendarios.refrendar', 'creditos_prendarios.liquidar', 'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar'],
        'secretaria' => ['empresas.ver', 'agencias.ver', 'usuarios.ver', 'usuarios.crear', 'clientes.ver', 'clientes.crear', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.crear', 'bienes.ver', 'creditos_prendarios.ver'],
        'administrador_agencia' => ['agencias.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'cajas.cerrar_forzado', 'bovedas.ver', 'bovedas.cerrar', 'billetajes.ver', 'billetajes.crear', 'billetajes.aprobar', 'billetajes.rechazar', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.aprobar', 'creditos_prendarios.rechazar', 'creditos_prendarios.firmar', 'creditos_prendarios.refrendar', 'creditos_prendarios.liquidar', 'configuraciones_credito_prendario.ver', 'configuraciones_credito_prendario.editar'],
        'peinadora' => ['clientes.ver', 'clientes.crear', 'clientes.editar'],
        'supervisor' => ['clientes.ver', 'clientes.asignar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.ver', 'billetajes.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.firmar', 'creditos_prendarios.refrendar', 'creditos_prendarios.liquidar'],
        'asesor' => ['clientes.ver', 'clientes.crear', 'clientes.editar', 'cajas.ver', 'cajas.aperturar', 'cajas.cerrar', 'billetajes.ver', 'billetajes.crear', 'bienes.ver', 'bienes.crear', 'bienes.editar', 'creditos_prendarios.ver', 'creditos_prendarios.crear', 'creditos_prendarios.firmar', 'creditos_prendarios.refrendar', 'creditos_prendarios.liquidar'],
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
            Role::where('name', $roleName)->firstOrFail()->syncPermissions($permissions);
        }
    }
}
