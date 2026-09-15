<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProduccionCreditosSeeder extends Seeder
{
    /**
     * FQCN de garantía por alias del morph map, para resolver `garantias` de
     * cada fila (ver credito_garantia en producción).
     *
     * @var array<string, class-string<Model>>
     */
    private const MODELO_POR_TIPO_GARANTIA = [
        'bien' => Bien::class,
        'vehiculo' => Vehiculo::class,
        'inmueble' => Inmueble::class,
    ];

    /**
     * Créditos reales ya registrados en producción, con sus garantías,
     * cuotas y cobros (ver database/seeders/data/produccion_creditos.php).
     * Debe correr después de Produccion{Clientes,Bienes,Vehiculos,
     * Inmuebles}Seeder — cada crédito se ata a su cliente y garantías por
     * clave natural, no por id.
     *
     * Inserta las filas directo (sin pasar por CreditoService::registrar/
     * aprobar/desembolsar) para conservar exactos los valores históricos de
     * cuotas/montos tal como quedaron en producción, en vez de recalcularlos
     * con la fórmula actual del motor (que puede haber cambiado desde
     * entonces). No genera documentos: quedan fuera de este alcance.
     */
    public function run(): void
    {
        $creditos = require $this->rutaDatos('produccion_creditos.php');

        foreach ($creditos as $datos) {
            $empresa = Empresa::where('nombre', $datos['empresa'])->firstOrFail();
            $agencia = Agencia::where('nombre', $datos['agencia'])->firstOrFail();
            $cliente = Cliente::query()
                ->where('empresa_id', $empresa->id)
                ->where('numero_documento', $datos['cliente_numero_documento'])
                ->firstOrFail();

            $credito = Credito::query()->firstOrCreate([
                'cliente_id' => $cliente->id,
                'tipo_credito' => $datos['tipo_credito'],
                'monto_prestamo' => $datos['monto_prestamo'],
                'fecha_desembolso' => $datos['fecha_desembolso'],
            ], [
                'empresa_id' => $empresa->id,
                'agencia_id' => $agencia->id,
                'registrado_por' => $this->usuarioId($datos['registrado_por_email']),
                'supervisado_por' => $this->usuarioId($datos['supervisado_por_email']),
                'numero_refrendo' => $datos['numero_refrendo'],
                'interes' => $datos['interes'],
                'interes_solicitud_especial' => $datos['interes_solicitud_especial'],
                'motivo_interes' => $datos['motivo_interes'],
                'tipo_cuota' => $datos['tipo_cuota'],
                'numero_cuotas' => $datos['numero_cuotas'],
                'plazo_dias' => $datos['plazo_dias'],
                'estado' => $datos['estado'],
                'aprobado_por' => $this->usuarioId($datos['aprobado_por_email']),
                'fecha_aprobacion' => $datos['fecha_aprobacion'],
                'motivo_rechazo' => $datos['motivo_rechazo'],
                'fecha_vencimiento' => $datos['fecha_vencimiento'],
            ]);

            if ($credito->wasRecentlyCreated) {
                // DatabaseSeeder corre con WithoutModelEvents: el hook de
                // Credito::booted() que asigna el código no se dispara.
                $credito->forceFill([
                    'codigo' => 'C-'.str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();

                $this->adjuntarGarantias($credito, $datos['garantias']);
                $this->crearCuotas($credito, $datos['cuotas']);
                $this->crearCobros($credito, $cliente, $datos['cobros']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $garantias
     */
    private function adjuntarGarantias(Credito $credito, array $garantias): void
    {
        foreach ($garantias as $garantiaDatos) {
            $modelo = self::MODELO_POR_TIPO_GARANTIA[$garantiaDatos['tipo']];

            // created_at desambigua bienes/inmuebles con nombre+serie o
            // partida_registral repetidos para el mismo cliente (ver
            // ProduccionBienesSeeder/ProduccionInmueblesSeeder) — sin esto,
            // firstOrFail() podría devolver el bien/inmueble equivocado.
            $garantia = match ($garantiaDatos['tipo']) {
                'bien' => Bien::query()
                    ->where('cliente_id', $credito->cliente_id)
                    ->where('nombre', $garantiaDatos['nombre'])
                    ->where('serie', $garantiaDatos['serie'])
                    ->where('created_at', $garantiaDatos['created_at'])
                    ->firstOrFail(),
                'vehiculo' => Vehiculo::query()
                    ->where('cliente_id', $credito->cliente_id)
                    ->where('placa', $garantiaDatos['placa'])
                    ->firstOrFail(),
                'inmueble' => Inmueble::query()
                    ->where('cliente_id', $credito->cliente_id)
                    ->where('partida_registral', $garantiaDatos['partida_registral'])
                    ->where('created_at', $garantiaDatos['created_at'])
                    ->firstOrFail(),
            };

            $credito->garantiasComo($modelo)->attach($garantia->id);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $cuotas
     */
    private function crearCuotas(Credito $credito, array $cuotas): void
    {
        foreach ($cuotas as $cuotaDatos) {
            CuotaCredito::query()->create([
                'credito_id' => $credito->id,
                'empresa_id' => $credito->empresa_id,
                'numero_cuota' => $cuotaDatos['numero_cuota'],
                'fecha_vencimiento' => $cuotaDatos['fecha_vencimiento'],
                'monto_capital' => $cuotaDatos['monto_capital'],
                'monto_interes' => $cuotaDatos['monto_interes'],
                'monto_total' => $cuotaDatos['monto_total'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $cobros
     */
    private function crearCobros(Credito $credito, Cliente $cliente, array $cobros): void
    {
        foreach ($cobros as $cobroDatos) {
            Cobro::query()->create([
                'empresa_id' => $credito->empresa_id,
                'cliente_id' => $cliente->id,
                'credito_id' => $credito->id,
                'registrado_por' => $this->usuarioId($cobroDatos['registrado_por_email']),
                'operacion' => $cobroDatos['operacion'],
                'monto_pagado' => $cobroDatos['monto_pagado'],
                'medio' => $cobroDatos['medio'],
                'interes' => $cobroDatos['interes'],
                'mora' => $cobroDatos['mora'],
                'descuento' => $cobroDatos['descuento'],
                'motivo_descuento' => $cobroDatos['motivo_descuento'],
                'vuelto' => $cobroDatos['vuelto'],
                'created_at' => $cobroDatos['created_at'],
                'updated_at' => $cobroDatos['created_at'],
            ]);
        }
    }

    private function usuarioId(?string $email): ?int
    {
        return $email ? User::where('email', $email)->firstOrFail()->id : null;
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
