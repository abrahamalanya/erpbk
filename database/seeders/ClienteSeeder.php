<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteFoto;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
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
    ];

    /**
     * Multiple photos (casa / negocio) live in `cliente_fotos`, not in columns.
     *
     * @var array<string, string>
     */
    private const FOTOS_MULTIPLES = [
        ClienteFoto::TIPO_CASA => 'casa.jpg',
        ClienteFoto::TIPO_NEGOCIO => 'negocio.jpg',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $agencia = Agencia::where('nombre', 'Agencia Alameda')->firstOrFail();

        $asesores = User::role('asesor')->where('agencia_id', $agencia->id)->orderBy('id')->get();

        if ($asesores->isEmpty()) {
            return;
        }

        $fotos = $this->publicarFotosDeMuestra();
        $fotosMultiples = $this->publicarFotosMultiplesDeMuestra();
        $distrito = UbigeoDistrito::query()->where('nombre', 'Yarinacocha')->first();

        foreach (range(1, 100) as $n) {
            $asesor = $asesores[$n % $asesores->count()];

            $cliente = Cliente::factory()
                ->asignadoA($asesor)
                ->create([
                    ...$fotos,
                    'ubigeo_distrito_id' => $distrito?->id,
                    'ubigeo_distrito_negocio_id' => $distrito?->id,
                    'registrado_por' => $asesor->id,
                ]);

            foreach ($fotosMultiples as $tipo => $path) {
                $cliente->fotos()->create(['tipo' => $tipo, 'path' => $path, 'orden' => 0]);
            }
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
            $paths[$column] = $this->publicarMuestra($filename);
        }

        return $paths;
    }

    /**
     * @return array<string, string> tipo => stored path
     */
    private function publicarFotosMultiplesDeMuestra(): array
    {
        $paths = [];

        foreach (self::FOTOS_MULTIPLES as $tipo => $filename) {
            $paths[$tipo] = $this->publicarMuestra($filename);
        }

        return $paths;
    }

    private function publicarMuestra(string $filename): string
    {
        $destino = "clientes/samples/{$filename}";
        $origen = public_path("img/{$filename}");

        if (! Storage::disk('public')->exists($destino) && is_file($origen)) {
            Storage::disk('public')->put($destino, file_get_contents($origen));
        }

        return $destino;
    }
}
