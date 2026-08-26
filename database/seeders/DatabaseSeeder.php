<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Catálogo/config real de CREDIMAS: seguro para producción, idempotente.
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            BancoSeeder::class,
            EmpresaSeeder::class,
            AgenciaSeeder::class,
            ConfiguracionCreditoPrendarioSeeder::class,
        ]);

        // Datos de demostración (usuarios/clientes/créditos ficticios): solo local.
        if (app()->environment('local')) {
            $this->call([
                CuentaBancariaSeeder::class,
                UserSeeder::class,
                BovedaSeeder::class,
                ClienteSeeder::class,
                BienSeeder::class,
                CreditoPrendarioSeeder::class,
            ]);
        }
    }
}
