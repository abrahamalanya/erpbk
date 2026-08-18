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
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'administrador_general' => ['empresas.ver', 'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar'],
        'secretaria' => ['empresas.ver', 'agencias.ver', 'usuarios.ver', 'usuarios.crear', 'clientes.ver', 'clientes.crear'],
        'administrador_agencia' => ['agencias.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'clientes.ver', 'clientes.crear', 'clientes.editar'],
        'peinadora' => ['clientes.ver', 'clientes.crear', 'clientes.editar'],
        'supervisor' => ['clientes.ver', 'clientes.asignar'],
        'asesor' => ['clientes.ver'],
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
