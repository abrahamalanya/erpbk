<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Ruta\Services\RutaCobranzaService;
use App\Modules\Ruta\Services\VisitaClienteService;
use App\Modules\Ubicacion\Services\UbicacionAsesorService;
use App\Modules\Usuario\Models\User;

/**
 * Fusiona lo que hoy son 3 mapas sueltos del panel de Inicio en una sola
 * respuesta: la ubicación en vivo de cada asesor visible (UbicacionAsesorService)
 * más su ruta de cobranza de hoy (RutaCobranzaService::rutaDe(), que ya trae
 * la ubicación de cobranza resuelta — Cliente::ubicacionCobranza() — y si el
 * cliente ya tiene un cobro válido hoy), cruzada con la permanencia que
 * VisitaClienteService fue confirmando en cada ping GPS.
 *
 * No duplica ninguna de esas reglas de negocio — solo las combina y agrega
 * el semáforo visual (pendiente/amarillo/verde) que el frontend no puede
 * calcular por sí mismo.
 */
final class MapaUnificadoService
{
    public function __construct(
        private readonly UbicacionAsesorService $ubicacionAsesorService,
        private readonly RutaCobranzaService $rutaCobranzaService,
        private readonly VisitaClienteService $visitaClienteService,
    ) {}

    /**
     * @return array{asesores: list<array<string, mixed>>, clientes: list<array<string, mixed>>, radio_proximidad_metros: float, minutos_permanencia: int}
     */
    public function paraActor(User $actor, ?int $agenciaId, ?int $asesorId): array
    {
        $radioProximidadMetros = (float) config('mapa_cobranza.radio_proximidad_metros');
        $minutosPermanencia = (int) config('mapa_cobranza.minutos_permanencia');

        $ubicaciones = $this->ubicacionAsesorService->visiblesPara($actor, $agenciaId, $asesorId);

        if ($ubicaciones->isEmpty()) {
            return [
                'asesores' => [],
                'clientes' => [],
                'radio_proximidad_metros' => $radioProximidadMetros,
                'minutos_permanencia' => $minutosPermanencia,
            ];
        }

        $minutosDesactualizada = (int) config('mapa_cobranza.minutos_ubicacion_desactualizada');
        $ahora = now();

        $asesorIds = $ubicaciones->pluck('asesor_id')->all();
        $usuariosPorId = User::query()->whereIn('id', $asesorIds)->get()->keyBy('id');
        $visitasPorAsesor = $this->visitaClienteService->visitasDeHoyPorAsesor($asesorIds);

        $asesores = $ubicaciones->map(fn (array $u): array => [
            'asesor_id' => $u['asesor_id'],
            'nombre' => $u['nombre'],
            'apellido' => $u['apellido'],
            'agencia_id' => $u['agencia_id'],
            'agencia_nombre' => $u['agencia_nombre'],
            'latitud' => $u['latitud'],
            'longitud' => $u['longitud'],
            'precision_metros' => $u['precision_metros'],
            'capturado_en' => $u['capturado_en']?->toISOString(),
            'desactualizado' => $u['capturado_en'] === null || $u['capturado_en']->diffInMinutes($ahora) > $minutosDesactualizada,
        ])->values();

        $clientes = collect();

        foreach ($asesorIds as $id) {
            $asesorUser = $usuariosPorId->get($id);

            if (! $asesorUser) {
                continue;
            }

            $visitasDeEsteAsesor = $visitasPorAsesor->get($id, collect());

            foreach ($this->rutaCobranzaService->rutaDe($asesorUser, null, $actor) as $parada) {
                $visita = $visitasDeEsteAsesor->get($parada['cliente_id']);
                $confirmadaEn = $visita?->confirmada_en;

                $clientes->push([
                    'cliente_id' => $parada['cliente_id'],
                    'asesor_id' => $id,
                    'orden' => $parada['orden'],
                    'nombre' => $parada['nombre'],
                    'apellido' => $parada['apellido'],
                    'numero_documento' => $parada['numero_documento'],
                    'lugar_cobranza' => $parada['lugar_cobranza'],
                    'direccion_cobranza' => $parada['direccion_cobranza'],
                    'referencia_cobranza' => $parada['referencia_cobranza'],
                    'latitud_cobranza' => $parada['latitud_cobranza'],
                    'longitud_cobranza' => $parada['longitud_cobranza'],
                    'cobrado_hoy' => $parada['cobrado_hoy'],
                    'estado_visual' => $parada['cobrado_hoy'] ? 'verde' : ($confirmadaEn !== null ? 'amarillo' : 'pendiente'),
                    'permanencia' => [
                        'inicio_en' => $visita?->inicio_en?->toISOString(),
                        'ultima_lectura_en' => $visita?->ultima_lectura_en?->toISOString(),
                        'confirmada_en' => $confirmadaEn?->toISOString(),
                    ],
                    'dias_atraso_max' => $parada['dias_atraso_max'],
                    'creditos_vencidos' => $parada['creditos_vencidos'],
                    'credito_codigos' => $parada['credito_codigos'],
                    'creditos' => $parada['creditos'],
                ]);
            }
        }

        return [
            'asesores' => $asesores->all(),
            'clientes' => $clientes->values()->all(),
            'radio_proximidad_metros' => $radioProximidadMetros,
            'minutos_permanencia' => $minutosPermanencia,
        ];
    }
}
