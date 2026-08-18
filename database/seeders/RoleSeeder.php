<?php

namespace Database\Seeders;

use App\Modules\Sistemas\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'sistemas',
            'administrador_general',
            'secretaria',
            'administrador_agencia',
            'peinadora',
            'supervisor',
            'asesor',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
