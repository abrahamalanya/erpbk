<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use App\Nucleo\Support\DocumentoIdentidad;

/**
 * Crea un distrito de prueba. Los tests corren contra sqlite :memory: con
 * RefreshDatabase, así que el catálogo del INEI (UbigeoSeeder) no está
 * cargado: se arma a mano como en ClienteUbigeoTest.
 */
function distritoDePrueba(string $departamento = '15', string $provincia = '01', string $distrito = '01'): UbigeoDistrito
{
    $dep = UbigeoDepartamento::firstOrCreate(['codigo' => $departamento], ['nombre' => 'Lima']);
    $prov = UbigeoProvincia::firstOrCreate(
        ['ubigeo_departamento_id' => $dep->id, 'codigo' => $provincia],
        ['nombre' => 'Lima']
    );

    return UbigeoDistrito::firstOrCreate(
        ['ubigeo_provincia_id' => $prov->id, 'codigo' => $distrito],
        ['nombre' => 'Lima']
    );
}

describe('DocumentoIdentidad', function () {
    it('acepta el RUC con el digito verificador correcto', function () {
        expect(DocumentoIdentidad::rucEsValido('20601030013'))->toBeTrue()
            ->and(DocumentoIdentidad::rucEsValido('20123456786'))->toBeTrue()
            ->and(DocumentoIdentidad::rucEsValido('20509030236'))->toBeTrue();
    });

    it('rechaza el RUC con el digito verificador incorrecto', function () {
        // El mismo RUC de arriba con el ultimo digito cambiado.
        expect(DocumentoIdentidad::rucEsValido('20601030014'))->toBeFalse()
            ->and(DocumentoIdentidad::rucEsValido('20123456789'))->toBeFalse();
    });

    it('rechaza un RUC que no tiene 11 digitos', function () {
        expect(DocumentoIdentidad::rucEsValido('2060103001'))->toBeFalse()
            ->and(DocumentoIdentidad::rucEsValido('206010300136'))->toBeFalse()
            ->and(DocumentoIdentidad::rucEsValido(''))->toBeFalse();
    });

    it('rechaza un RUC con letras', function () {
        expect(DocumentoIdentidad::rucEsValido('20A0130013'))->toBeFalse();
    });

    it('explica por que el RUC es invalido en vez de decir "documento invalido"', function () {
        try {
            DocumentoIdentidad::validar('ruc', '20123456789');
            $this->fail('Debió lanzar DomainException.');
        } catch (DomainException $e) {
            expect($e->getMessage())->toBe('El RUC no es válido: el dígito verificador no coincide.');
        }
    });

    it('explica la longitud esperada cuando el documento no cuadra', function () {
        expect(fn () => DocumentoIdentidad::validar('dni', '123456789'))
            ->toThrow(DomainException::class, 'El dni debe tener 8 caracteres.');

        expect(fn () => DocumentoIdentidad::validar('dni', '123'))
            ->toThrow(DomainException::class, 'El dni debe tener 8 caracteres.');

        expect(fn () => DocumentoIdentidad::validar('ruc', '2060103001'))
            ->toThrow(DomainException::class, 'El ruc debe tener 11 caracteres.');
    });

    it('rechaza un DNI con letras porque solo admite digitos', function () {
        expect(fn () => DocumentoIdentidad::validar('dni', '1234567A'))
            ->toThrow(DomainException::class, 'El dni debe tener solo dígitos.');
    });

    it('acepta el CE alfanumerico porque el carnet de extranjeria no es solo digitos', function () {
        // El CE real tiene formato X1234567A: si se exigieran solo dígitos,
        // ningún extranjero podría registrarse.
        DocumentoIdentidad::validar('ce', 'X1234567A');

        expect(DocumentoIdentidad::longitudDe('ce'))->toBe(9);
    });

    it('acepta el pasaporte en alfanumerico sin fijar longitud', function () {
        // El pasaporte cambia de formato según el país emisor.
        DocumentoIdentidad::validar('pasaporte', 'AB1234567');
        DocumentoIdentidad::validar('pasaporte', 'X');

        expect(fn () => DocumentoIdentidad::validar('pasaporte', 'AB-123/456'))
            ->toThrow(DomainException::class, 'El número de pasaporte solo puede tener letras y dígitos.');
    });

    it('rechaza un tipo de documento fuera del catalogo', function () {
        expect(fn () => DocumentoIdentidad::validar('licencia_de_conducir', 'Q12345678'))
            ->toThrow(DomainException::class, "El tipo de documento 'licencia_de_conducir' no es válido.");
    });

    it('expone el catalogo con ruc incluido para poder emitir facturas', function () {
        expect(DocumentoIdentidad::tipos())->toBe(['dni', 'ruc', 'ce', 'pasaporte'])
            ->and(DocumentoIdentidad::esValido('ruc'))->toBeTrue()
            ->and(DocumentoIdentidad::esValido('carnet'))->toBeFalse();
    });

    it('indica que un RUC exige factura y el resto boleta', function () {
        expect(DocumentoIdentidad::comprobantePara('ruc'))->toBe('factura')
            ->and(DocumentoIdentidad::comprobantePara('dni'))->toBe('boleta')
            ->and(DocumentoIdentidad::comprobantePara('ce'))->toBe('boleta')
            ->and(DocumentoIdentidad::comprobantePara('pasaporte'))->toBe('boleta');
    });
});

describe('datos fiscales de la empresa', function () {
    it('arma la direccion fiscal en el orden que exige SUNAT', function () {
        $empresa = Empresa::factory()->create([
            'domicilio_calle' => 'Av. Javier Prado Este',
            'domicilio_numero' => '4200',
            'domicilio_interior' => 'Piso 3',
        ]);

        expect($empresa->datosFiscales()['direccion'])->toBe('Av. Javier Prado Este N.° 4200 Piso 3');
    });

    it('cae al domicilio_legal cuando la direccion estructurada aun no se cargo', function () {
        // Una empresa puede tener RUC y razon social desde antes de que existiera
        // el formulario de datos fiscales; el comprobante igual debe salir.
        $empresa = Empresa::factory()->create([
            'domicilio_calle' => null,
            'domicilio_numero' => null,
            'domicilio_interior' => null,
            'domicilio_legal' => 'Av. Siempre Viva 742',
        ]);

        expect($empresa->datosFiscales()['direccion'])->toBe('Av. Siempre Viva 742');
    });

    it('devuelve el ubigeo de 6 digitos que pide el XML', function () {
        $distrito = distritoDePrueba();
        $empresa = Empresa::factory()->create(['ubigeo_distrito_id' => $distrito->id]);

        $ubigeo = $empresa->datosFiscales()['ubigeo'];

        expect($ubigeo)->toBe('150101')->toHaveLength(6);
    });

    it('arma el ubigeo concatenando los tres niveles del catalogo INEI', function () {
        // Ucayali / Coronel Portillo / Manantay = 250105
        $distrito = distritoDePrueba('25', '01', '05');
        $empresa = Empresa::factory()->create(['ubigeo_distrito_id' => $distrito->id]);

        expect($empresa->datosFiscales()['ubigeo'])->toBe('250105')
            ->and($distrito->ubigeo())->toBe('250105');
    });

    it('lista los datos fiscales que faltan para poder emitir', function () {
        $empresa = Empresa::factory()->create([
            'ruc' => null,
            'razon_social' => null,
            'domicilio_calle' => null,
            'ubigeo_distrito_id' => null,
        ]);

        expect($empresa->datosFiscalesFaltantes())->toBe(['RUC', 'razón social', 'domicilio fiscal']);
    });

    it('no reporta faltantes cuando la empresa esta completa', function () {
        $distrito = distritoDePrueba();
        $empresa = Empresa::factory()->create([
            'ruc' => '20601030013',
            'razon_social' => 'CREDIMAS PERU S.A.C.',
            'domicilio_calle' => 'Av. Javier Prado Este',
            'domicilio_numero' => '4200',
            'ubigeo_distrito_id' => $distrito->id,
        ]);

        expect($empresa->datosFiscalesFaltantes())->toBe([]);
    });
});

describe('punto de emision de la agencia', function () {
    it('permite guardar el codigo de establecimiento que asigna SUNAT', function () {
        $distrito = distritoDePrueba();
        $agencia = Agencia::factory()->create([
            'ruc' => null,
            'domicilio_calle' => 'Av. Alameda Central',
            'domicilio_numero' => '1234',
            'ubigeo_distrito_id' => $distrito->id,
            'codigo_establecimiento' => '0001',
        ]);

        expect($agencia->codigo_establecimiento)->toBe('0001')
            // La mayoría de sucursales emiten con el RUC de la casa matriz.
            ->and($agencia->ruc)->toBeNull()
            ->and($agencia->ubigeoDistrito)->not->toBeNull();
    });
});
