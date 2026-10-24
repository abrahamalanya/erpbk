<?php

namespace App\Modules\Cliente\Http\Controllers;

use App\Modules\Cliente\Http\Requests\AsignarClienteRequest;
use App\Modules\Cliente\Http\Requests\StoreClienteRequest;
use App\Modules\Cliente\Http\Requests\UpdateClienteRequest;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteFoto;
use App\Modules\Cliente\Services\ClienteFotoService;
use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Modules\Cliente\Services\ClienteTelefonoService;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\GestionaImagenes;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ConsultaDniService;
use App\Nucleo\Services\ConsultaRucService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class ClienteController extends Controller
{
    use ApiResponse, GestionaImagenes;

    /**
     * Fotos de un solo archivo: cada una se persiste en su columna
     * `{campo}_path` dentro de la carpeta `clientes`.
     *
     * @var list<string>
     */
    private const IMAGENES = ['foto_cliente', 'foto_dni', 'foto_dni_reverso', 'foto_suministro', 'foto_recibo_luz'];

    /**
     * Todo campo del request que llega como archivo y por lo tanto nunca debe
     * pasar por mass assignment: los de foto única, los arreglos de fotos
     * múltiples y sus listas de ids a conservar.
     *
     * @var list<string>
     */
    private const CAMPOS_ARCHIVO = [
        'foto_cliente', 'foto_dni', 'foto_dni_reverso', 'foto_suministro', 'foto_recibo_luz',
        'fotos_casa', 'fotos_casa_conservar',
        'fotos_negocio', 'fotos_negocio_conservar',
        'fotos_adicionales', 'fotos_adicionales_conservar',
    ];

    /**
     * Campos que se resuelven aparte, vía relación, y por lo tanto tampoco
     * deben pasar por mass assignment sobre el propio Cliente.
     *
     * @var list<string>
     */
    private const CAMPOS_RELACION = ['telefonos'];

    /**
     * Relaciones que se cargan siempre: `fotos`/`telefonos` alimentan la
     * respuesta y el PDF del expediente, `ubigeoDistrito*` son accessors en
     * $appends.
     *
     * @var list<string>
     */
    private const RELACIONES = [
        'agencia', 'asesor', 'registradoPor', 'fotos', 'telefonos',
        'ubigeoDistrito.provincia.departamento', 'ubigeoDistritoNegocio.provincia.departamento',
    ];

    public function __construct(
        private readonly ClienteHierarchyService $hierarchy,
        private readonly ClienteFotoService $fotos,
        private readonly ClienteTelefonoService $telefonos,
        private readonly ConsultaDniService $consultaDni,
        private readonly ConsultaRucService $consultaRuc,
    ) {}

    public function consultarDni(string $dni): JsonResponse
    {
        Gate::authorize('create', Cliente::class);

        return $this->successResponse($this->consultaDni->consultar($dni));
    }

    /**
     * Autocompleta los datos de una persona jurídica. Necesario para poder
     * emitir una factura a un cliente con RUC — toda factura electrónica
     * exige RUC de receptor (FACTURACION.md §3.3).
     */
    public function consultarRuc(string $ruc): JsonResponse
    {
        Gate::authorize('create', Cliente::class);

        return $this->successResponse($this->consultaRuc->consultar($ruc));
    }

    /**
     * Asesores elegibles para el diálogo "Asignar cliente", acotados a una
     * agencia — administrador_general elige entre las de su empresa;
     * administrador_agencia/supervisor quedan fijos a la suya propia
     * (resolveAgenciaId ignora cualquier agencia_id que manden). Un
     * supervisor además solo ve a sus propios subordinados, igual que
     * ClientePolicy::asignar().
     */
    public function asesoresParaAsignar(): JsonResponse
    {
        $actor = request()->user();

        abort_unless($actor->can('clientes.asignar'), 403);

        $agenciaId = $this->hierarchy->resolveAgenciaId($actor, request()->integer('agencia_id') ?: null);

        if (! $agenciaId) {
            return $this->successResponse([]);
        }

        $query = User::role('asesor')
            ->where('agencia_id', $agenciaId)
            ->where('estado', 'activo');

        if ($actor->hasRole('supervisor')) {
            $query->where('supervisor_id', $actor->id);
        }

        return $this->successResponse($query->orderBy('nombre')->get(['id', 'nombre', 'apellido', 'agencia_id', 'supervisor_id']));
    }

    public function index(): JsonResponse
    {
        return $this->listado(false);
    }

    public function opciones(): JsonResponse
    {
        return $this->listado(true);
    }

    public function opcion(Cliente $cliente): JsonResponse
    {
        Gate::authorize('view', $cliente);

        return $this->successResponse($cliente->only(self::CAMPOS_OPCION));
    }

    private const CAMPOS_OPCION = ['id', 'nombre', 'apellido', 'tipo_documento', 'numero_documento', 'empresa_id', 'agencia_id', 'asesor_id', 'estado'];

    private function listado(bool $opciones): JsonResponse
    {
        Gate::authorize('viewAny', Cliente::class);

        $query = Cliente::query()->when(! $opciones, fn (Builder $q) => $q->with(self::RELACIONES));
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        if (request()->filled('q')) {
            $termino = trim((string) request()->string('q'));
            $query->where(function (Builder $sub) use ($termino): void {
                $sub->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('apellido', 'like', "%{$termino}%")
                    ->orWhere('numero_documento', 'like', "%{$termino}%");
            });
        }

        if (request()->filled('estado')) {
            $query->where('estado', (string) request()->string('estado'));
        }

        if (request()->filled('tipo_documento')) {
            $query->where('tipo_documento', (string) request()->string('tipo_documento'));
        }

        if (request()->filled('agencia_id')) {
            $query->where('agencia_id', request()->integer('agencia_id'));
        }

        if (request()->filled('asesor_id')) {
            $query->where('asesor_id', request()->integer('asesor_id'));
        }

        $porPagina = max(1, min(request()->integer('per_page', 50), 100));

        $resultado = $query->paginate($porPagina, $opciones ? self::CAMPOS_OPCION : ['*']);
        if ($opciones) {
            $resultado->through(fn (Cliente $cliente): array => $cliente->only(self::CAMPOS_OPCION));
        }

        return $this->successResponse($resultado);
    }

    public function store(StoreClienteRequest $request): JsonResponse
    {
        Gate::authorize('create', Cliente::class);

        $data = Arr::except($request->validated(), [...self::CAMPOS_ARCHIVO, ...self::CAMPOS_RELACION]);
        $actor = $request->user();

        $cliente = Cliente::query()->create([
            'empresa_id' => $actor->hasRole('sistemas') ? $data['empresa_id'] : $actor->empresa_id,
            'agencia_id' => $this->hierarchy->resolveAgenciaId($actor, $data['agencia_id'] ?? null),
            'asesor_id' => $this->hierarchy->resolveAsesorId($actor),
            'registrado_por' => $actor->id,
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'tipo_documento' => $data['tipo_documento'],
            'numero_documento' => $data['numero_documento'],
            'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
            'sexo' => $data['sexo'] ?? null,
            'estado_civil' => $data['estado_civil'] ?? null,
            'email' => $data['email'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'direccion' => $data['direccion'] ?? null,
            'ubigeo_distrito_id' => $data['ubigeo_distrito_id'] ?? null,
            'referencia' => $data['referencia'] ?? null,
            'latitud' => $data['latitud'] ?? null,
            'longitud' => $data['longitud'] ?? null,
            'direccion_negocio' => $data['direccion_negocio'] ?? null,
            'ubigeo_distrito_negocio_id' => $data['ubigeo_distrito_negocio_id'] ?? null,
            'referencia_negocio' => $data['referencia_negocio'] ?? null,
            'latitud_negocio' => $data['latitud_negocio'] ?? null,
            'longitud_negocio' => $data['longitud_negocio'] ?? null,
            'lugar_cobranza' => $data['lugar_cobranza'] ?? 'casa',
            'estado' => 'activo',
        ]);

        $this->storeImagenes($request, $cliente, self::IMAGENES, 'clientes');
        $this->fotos->sincronizar($cliente, $request);
        $this->telefonos->sincronizar($cliente, $request);

        return $this->successResponse(
            $cliente->fresh(self::RELACIONES),
            'Cliente creado',
            201
        );
    }

    public function show(Cliente $cliente): JsonResponse
    {
        Gate::authorize('view', $cliente);

        return $this->successResponse($cliente->load(self::RELACIONES));
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente): JsonResponse
    {
        Gate::authorize('update', $cliente);

        $data = Arr::except($request->validated(), [...self::CAMPOS_ARCHIVO, ...self::CAMPOS_RELACION]);
        $cliente->update($data);

        $this->storeImagenes($request, $cliente, self::IMAGENES, 'clientes');
        $this->fotos->sincronizar($cliente, $request);
        $this->telefonos->sincronizar($cliente, $request);

        return $this->successResponse(
            $cliente->fresh(self::RELACIONES),
            'Cliente actualizado'
        );
    }

    /**
     * Borra una foto múltiple del cliente (casa, negocio o adicional). Se
     * resuelve siempre a través del cliente para que un id ajeno no exista.
     */
    public function eliminarFoto(Cliente $cliente, ClienteFoto $foto): JsonResponse
    {
        Gate::authorize('update', $cliente);

        abort_unless($foto->cliente_id === $cliente->id, 404);

        $this->fotos->eliminar($foto);

        return $this->successResponse(null, 'Foto eliminada');
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        Gate::authorize('delete', $cliente);

        $this->fotos->eliminarTodas($cliente);
        $this->eliminarImagenes($cliente, self::IMAGENES);
        $cliente->delete();

        return $this->successResponse(null, 'Cliente eliminado');
    }

    public function asignar(AsignarClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $asesorId = (int) $request->validated('asesor_id');
        Gate::authorize('asignar', [$cliente, $asesorId]);

        $cliente->update(['asesor_id' => $asesorId]);

        return $this->successResponse($cliente->fresh(['asesor']), 'Cliente asignado');
    }
}
