<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
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
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'administrador_general' => ['empresas.ver', 'agencias.ver', 'agencias.crear', 'agencias.editar', 'agencias.eliminar', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar'],
        'secretaria' => ['empresas.ver', 'agencias.ver', 'usuarios.ver', 'usuarios.crear'],
        'administrador_agencia' => ['agencias.ver', 'usuarios.ver', 'usuarios.crear', 'usuarios.editar'],
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
