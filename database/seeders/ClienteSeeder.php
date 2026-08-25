<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ClienteSeeder extends Seeder
{
    /**
     * Cliente photo column => sample filename in public/img. Every seeded
     * cliente points at the SAME copy on the public disk (published once by
     * publicarFotosDeMuestra()) instead of duplicating these files 100
     * times — this is demo data, not real client documents.
     *
     * @var array<string, string>
     */
    private const FOTOS = [
        'foto_cliente_path' => 'perfil.jpg',
        'foto_dni_path' => 'dni1.jpeg',
        'foto_dni_reverso_path' => 'dni2.jpg',
        'foto_casa_path' => 'casa.jpg',
        'foto_negocio_path' => 'negocio.jpg',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $agencia = Agencia::where('nombre', 'Agencia Pucallpa')->firstOrFail();

        $asesores = User::role('asesor')->where('agencia_id', $agencia->id)->orderBy('id')->get();

        if ($asesores->isEmpty()) {
            return;
        }

        $fotos = $this->publicarFotosDeMuestra();

        foreach (range(1, 100) as $n) {
            $asesor = $asesores[$n % $asesores->count()];

            Cliente::factory()
                ->asignadoA($asesor)
                ->create([
                    ...$fotos,
                    'registrado_por' => $asesor->id,
                ]);
        }
    }

    /**
     * Copies each sample photo from public/img into the public disk (once)
     * and returns the column => stored-path map to merge into every
     * seeded cliente.
     *
     * @return array<string, string>
     */
    private function publicarFotosDeMuestra(): array
    {
        $paths = [];

        foreach (self::FOTOS as $column => $filename) {
            $destino = "clientes/samples/{$filename}";
            $origen = public_path("img/{$filename}");

            if (! Storage::disk('public')->exists($destino) && is_file($origen)) {
                Storage::disk('public')->put($destino, file_get_contents($origen));
            }

            $paths[$column] = $destino;
        }

        return $paths;
    }
}
