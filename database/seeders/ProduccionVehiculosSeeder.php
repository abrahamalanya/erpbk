<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProduccionVehiculosSeeder extends Seeder
{
    /**
     * Vehículos reales ya registrados en producción (ver
     * database/seeders/data/produccion_vehiculos.php). Debe correr después
     * de ProduccionClientesSeeder: cada vehículo se ata a su cliente por
     * número de documento, no por id.
     */
    public function run(): void
    {
        $vehiculos = require $this->rutaDatos('produccion_vehiculos.php');

        foreach ($vehiculos as $datos) {
            $empresa = Empresa::where('nombre', $datos['empresa'])->firstOrFail();
            $agencia = Agencia::where('nombre', $datos['agencia'])->firstOrFail();

            $cliente = Cliente::query()
                ->where('empresa_id', $empresa->id)
                ->where('numero_documento', $datos['cliente_numero_documento'])
                ->firstOrFail();

            $vehiculo = Vehiculo::query()->firstOrCreate([
                'cliente_id' => $cliente->id,
                'placa' => $datos['placa'],
            ], [
                'empresa_id' => $empresa->id,
                'agencia_id' => $agencia->id,
                'registrado_por' => $datos['registrado_por_email'] ? User::where('email', $datos['registrado_por_email'])->firstOrFail()->id : null,
                'motor' => $datos['motor'],
                'serie' => $datos['serie'],
                'color' => $datos['color'],
                'marca' => $datos['marca'],
                'modelo' => $datos['modelo'],
                'anio' => $datos['anio'],
                'clase' => $datos['clase'],
                'propietario' => $datos['propietario'],
                'tiene_soat' => $datos['tiene_soat'],
                'dejo_llave' => $datos['dejo_llave'],
                'dejo_tarjeta_propiedad' => $datos['dejo_tarjeta_propiedad'],
                'observacion' => $datos['observacion'],
                'valorizacion' => $datos['valorizacion'],
                'precio_venta' => $datos['precio_venta'],
                'puntaje' => $datos['puntaje'],
                'foto_cliente_producto_path' => $datos['foto_cliente_producto_path'],
                'video_path' => $datos['video_path'],
                'estado' => $datos['estado'],
            ]);

            // DatabaseSeeder corre con WithoutModelEvents: el hook de
            // EsGarantia::bootEsGarantia() que asigna el código no se
            // dispara.
            if ($vehiculo->wasRecentlyCreated) {
                $vehiculo->forceFill([
                    'codigo' => 'V-'.str_pad((string) $vehiculo->id, 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        }
    }

    /**
     * Estos archivos guardan PII real (nombres, DNI, teléfonos) y por eso
     * están en .gitignore: solo existen en el servidor de producción, no en
     * el repositorio. Si faltan, es porque no se subieron todavía.
     */
    private function rutaDatos(string $archivo): string
    {
        $ruta = database_path("seeders/data/{$archivo}");

        if (! file_exists($ruta)) {
            throw new RuntimeException("Falta el archivo de datos de producción: {$ruta}. Súbelo al servidor antes de sembrar.");
        }

        return $ruta;
    }
}
