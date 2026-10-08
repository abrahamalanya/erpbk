# Plan de desarrollo — Módulo de Facturación Electrónica (SUNAT)

> Documento de planificación técnica y funcional.
> Fecha de corte: 2026-10-06. Basado en el estado real del código (`app/`, `database/`) a esa fecha.

---

## 1. Resumen ejecutivo

Se implementará **facturación electrónica SUNAT completa** (comprobantes reales, firmados y comunicados), con la regla de negocio central definida por el cliente:

> **Se factura únicamente el interés cobrado, y se emite cuando el crédito queda cancelado.** El capital devuelto al cliente no se factura porque no constituye ingreso para la financiera; la operación gravada con IGV es la prestación del servicio crediticio.

Ejemplo de referencia del cliente: crédito de `S/ 1,000` con total a pagar `S/ 1,100` → al cancelarse, se emite **una boleta o factura por `S/ 100`** (el interés).

El módulo es **nuevo**: hoy el sistema **no tiene** ningún concepto de comprobante fiscal, serie, correlativo, impuesto ni integración con SUNAT. No es una extensión de algo existente sino la incorporación de un subsistema completo.

**Estimación de esfuerzo técnico:** 5–7 semanas de desarrollo backend + 2–3 semanas de frontend, **en paralelo** con un camino crítico no-code de 3–6 semanas (certificado, credenciales, homologación SUNAT) que puede bloquear el go-live.

---

## 2. Estado actual del sistema (hallazgos)

Inventario de lo que **existe** y de lo que **falta**. Esta sección es la base de todas las decisiones de diseño de la sección 5.

### 2.1 Lo que ya existe y se reutiliza

| Recurso | Ubicación | Uso en facturación |
|---|---|---|
| Multi-tenancy por `empresa_id` | `app/Nucleo/Scopes/TenantScope.php`, `app/Nucleo/Concerns/BelongsToTenant.php` | Las credenciales SUNAT y las series son **por empresa** → tablas nuevas con el trait, sin plomería extra. |
| Render Blade → PDF | `app/Nucleo/Services/PdfGeneratorService.php` | PDF del comprobante (representación impresa). |
| Importe en letras | `app/Nucleo/Support/NumeroALetras.php::soles()` | `importeTotalEnLetras` es **obligatorio** en el XML. Ya está resuelto. |
| Export Excel | `app/Nucleo/Services/ExcelGeneratorService.php` | Reporte de boletas emitidas / libro de ventas. |
| QR como data-URI | `app/Nucleo` + `endroid/qr-code` ya usado en stickers de crédito | El QR del comprobante se genera con la misma librería. |
| Patrón de integración HTTP externa | `app/Nucleo/Services/ConsultaDniService.php` | Plantilla para el cliente SUNAT: endpoint en `const`, secretos en `config()`, `Http::` con timeout, errores a `DomainException`, respuesta normalizada. |
| Patrón de configuración por empresa + override por agencia | `app/Modules/Venta/Models/ConfiguracionVenta.php` + `ConfiguracionVentaService::resolverPara()` | Idéntico para la configuración de facturación. |
| Patrón de tabla de auditoría de documentos | `documentos_venta`, `documentos_credito_prendario` | El comprobante hereda este patrón. |
| Colas (`database`) + `composer run dev` arranca `queue:listen` | `.env.example`, `composer.json` | Base para el envío asíncrono a SUNAT. |
| Comandos programados diarios | `routes/console.php` | Resumen diario, reintentos, alertas. |
| Catálogo de ubigeo INEI | `app/Modules/Ubigeo` (`ubigeo_departamentos/provincias/distritos`, seedeado) | El XML exige el **ubigeo de 6 dígitos** de emisor y receptor. Se reutiliza tal cual. |

### 2.2 Lo que falta y hay que construir

| Falta | Impacto |
|---|---|
| **Ningún concepto de comprobante fiscal.** Cero coincidencias de `sunat`, `igv`, `factura`, `boleta`, `tipo_comprobante` en `app/`. | Greenfield. |
| **Ningún sistema de series ni correlativos.** No hay `serie`, ni `correlativo`, ni tabla de secuencia, ni caché de numeración. Lo único numerado es `str_pad($id, 6)` para el código del crédito y el voucher interno. | Hay que construir el correlativo **sin huecos** (requisito SUNAT). |
| **Sin impuestos.** Todas las columnas monetarias son `decimal` crudas; no hay `subtotal` / `igv` / `total`. | Hay que decidir y modelar la base imponible. |
| **La fuente del interés sí existe.** `cobros` tiene `interes`, `mora`, `descuento`, `motivo_descuento`, `vuelto`, `estado` (`registrado`/`anulado`) y `credito_id`. `cuotas_credito_prendario` tiene `monto_capital` / `monto_interes` / `monto_total`. | **No hay que recalcular nada**: se agrega lo ya registrado. |
| **El cliente no puede tener RUC.** `clientes.tipo_documento` está restringido a `dni \| ce \| pasaporte` en `StoreClienteRequest.php:34` y `UpdateClienteRequest.php:30`. `nombre` y `apellido` son dos campos separados (no 4 como pide el UBL). | Sin esto no se puede emitir **factura** (toda factura exige RUC de receptor). |
| **El emisor no tiene domicilio fiscal estructurado.** `empresas` tiene `ruc` (`string(11)`, nullable, sin `unique` ni validación de dígito verificador) y `domicilio_legal` como texto libre de 255. `agencias` no tiene RUC ni código de establecimiento. | El XML exige dirección estructurada + ubigeo + **punto de emisión** por establecimiento. |
| **No hay soporte de roles fiscales:** ni certificado digital, ni usuario/clave SOL, ni modo homologación/producción. | Implementar, cifrado en reposo y disco **privado** (nunca `public`). |
| **No hay framework SOAP/XML.** No hay `ext-soap` ni `ext-dom` declarados, ni paquete de firma. Solo hay Guzzle vía `illuminate/http`. | Dependencia nueva (ver riesgo en §11). |
| **El proyecto evita deliberadamente la cola.** `NotificacionService` documenta que no hay worker y por eso no usa el canal `broadcast` de Laravel. | El envío a SUNAT sería el **primer** `ShouldQueue` real. Requiere decisión de infraestructura. |
| **No hay jobs.** `app/Jobs/` no existe. | Crear el primer job + comando de reintento. |
| **El flujo de venta no emite eventos de caja.** `VentaService::registrarPago()` no despacha `CajaActualizada` (a diferencia de `CreditoService::registrarCobroEnCaja()`), y `cancelarInterna()` no revierte el `CajaMovimiento`. | Bugs preexistentes que conviene corregir en la misma fase, porque la facturación se apoya en esa trazabilidad. |
| **Los cobros de venta no se pueden anular.** `pagos_venta` tiene `anulado_por` / `anulado_at` / `motivo_anulacion` pero ningún servicio los escribe. | Si se factura la venta, hace falta el mismo flujo de anulación que sí existe en `CreditoService::anularCobro()`. |

### 2.3 Requisito pendiente ya anotado por el cliente

`APUNTES.md:51-58` ya pedía:

```
MODULO FACTURACION
- SELECCIONAR EL CLIENTE PARA EMITIR LA FACTURACION
- CLIENTE AGREGAR DNI/RUC/CE
**ALERTA PARA EMITIR FACTURACION DIARIO**
CONTADOR TOTAL DE CUANTO SE HA EMITIDO HASTA LA FECHA
REPORTE DE LAS BOLETAS EMITIDAS
```

Los cuatro puntos están cubiertos en este plan (§7, §9, §10).

---

## 3. Reglas de negocio

### 3.1 Qué se factura (la regla central)

La base imponible de un crédito es **la suma del interés efectivamente cobrado**, nunca el capital.

```
Base imponible del crédito = Σ (cobros.interes + cobros.mora − cobros.descuento)
                             donde cobros.estado = 'registrado'
                             y cobros.credito_id = :credito
```

| Componente | Se factura | Razón |
|---|---|---|
| Capital (`monto_prestamo`, `cuotas.monto_capital`) | **No** | Es devolución del principal, no ingreso. |
| Interés (`cobros.interes`) | **Sí** | Prestación del servicio crediticio. |
| Mora (`cobros.mora`) | **Sí** | Sanción íntegramente gravada; se factura en su propia línea. |
| Descuento / condonación (`cobros.descuento`) | **Resta** | Reduce la base. Requiere la línea documentada en `motivo_descuento`. |
| Vuelto (`cobros.vuelto`) | **No** | Nunca entra en la base. |
| Comisiones / seguros / gastos | **Sí**, cuando existan | Líneas separadas en el detalle del comprobante. |

**Fuentes de verdad y consistencia.** El sistema tiene dos fuentes de interés y pueden diferir:

- **Devengado** (`cuotas_credito_prendario.monto_interes`): lo que el cronograma dice.
- **Cobrado** (`cobros.interes`): lo que el cliente efectivamente pagó.

**Recomendación: base "cobrado" desde `cobros`.** Es la que coincide con el ingreso en caja, con el Libro de Ventas y con lo que el cliente final pide. Se debe añadir un **test de consistencia** que compare `Σ cobros.interes` contra `Σ cuotas.monto_interes` y que reporte la diferencia cuando exista (ocurre cuando hay descuentos, quitas o refinancing). Si la diferencia es material, se exige confirmación del supervisor antes de emitir.

### 3.2 Cuándo se factura

La factura se emite **cuando el crédito queda cancelado**, no cuota por cuota.

- Créditos elegibles: `creditos_prendarios.estado IN ('liquidado', 'liquidado_pendiente')` y **sin comprobante emitido**.
- Para los créditos `diario` el estado terminal es `liquidado` directamente (ver `CreditoService.php:1713`); para prendario/vehicular/hipotecario pasa por `liquidado_pendiente` hasta que se firma el acta de devolución (`CreditoService.php:2215`). **La factura debe poder emitirse en ambos estados** — no exigir esperar el acta, porque el dinero ya se cobró.
- **Regla de bloqueo crítico:** si un cobro del crédito se anula después de emitido el comprobante, la factura queda desactualizada. El sistema debe detectarlo y bloquear nuevas emisiones del crédito, forzando nota de crédito (ver §5.8).

### 3.3 Boleta o factura

| Condición | Comprobante |
|---|---|
| Receptor con RUC | **Factura** (tipo `01`). Obligatorio. |
| Receptor con DNI / CE / pasaporte | **Boleta de venta** (tipo `03`). |
| Persona jurídica sin RUC | **No se puede emitir** → el flujo debe bloquearlo y pedir el RUC. |

**Umbral de identificación del consumidor final.** Las boletas a personas naturales identificadas con DNI están limitadas en monto (regla de los S/ 700 acumulados por día y S/ 1,000 por comprobante). El sistema **debe validar y advertir** este umbral antes de emitir, porque superarlo es una infracción del cliente, no un error del software. **Verificar el monto vigente y las condiciones exactas con SUNAT** (§12).

### 3.4 IGV sobre el interés

El interés cobrado por la financiera normalmente **incluye IGV**. Por tanto:

```
Base imponible = Interés total / 1.18
IGV            = Base imponible × 0.18
Total          = Interés total
```

Esto debe ser **configurable por empresa** (`interes_incluye_igv`, por defecto `true`), porque no todas las operaciones ni todos los clientes esperan el mismo tratamiento, y porque el sistema ya vende garantías con precios brutos (`ventas.precio_venta` es un monto único, sin desglose). Si la configuración es `false`, se aplica `Base = Interés total; IGV = Interés × 0.18`.

### 3.5 Reglas operativas heredadas del sistema

- La emisión exige **caja abierta** del actor (mismo criterio que `VentaService::cicloAbiertoDe()` y `CreditoService::resolverCicloParaCobro()`).
- El actor debe tener `facturaciones.crear`.
- Todo queda en **notificación interna + tiempo real** (`ComprobanteActualizado`, `NotificacionCreada`), siguiendo el patrón de `CajaActualizada`.
- Los documentos se **re-renderizan al vuelo** desde la fila `comprobante` (nunca se guarda el PDF como fuente de verdad), igual que `DocumentoCreditoService`. El XML y el CDR **sí** se archivan, porque son los que se enviaron a SUNAT y no deben regenerarse.

---

## 4. Requisitos previos (no son código, pero bloquean el go-live)

> **Esta es la parte que más se subestima.** El desarrollo puede estar listo y el go-live igual quedar bloqueado por cualquiera de estos puntos.

### 4.1 Homologación del software ante SUNAT — CRÍTICO

SUNAT exige que todo software que emite comprobantes electrónicos sea **homologado**. Sin homologación, el software es **no compliant** y el emisor queda expuesto a sanción, aun si los XML son técnicamente válidos y fueron aceptados.

El proceso de homologación verifica que el software:

- Genere XML válido y firmado.
- Maneje correctamente los errores y rechazos.
- Obtenga y muestre el **CDR**.
- Genere la **representación impresa** (PDF) con los datos obligatorios.
- Implemente el **código de seguridad** o el **QR** de verificación.
- Gestione el **resumen diario** de boletas.
- Gestione la **anulación / comunicación de baja**.
- Gestione series y correlativos sin huecos.

**Impacto en el cronograma:** este trámite corre en paralelo con el desarrollo, pero **debe iniciarse antes de tener el software completo**, porque la SUNAT suele pedir evidencia funcional. Planificar 3–6 semanas.

> **Considerar la Opción B (§4.7):** si el riesgo de homologación propia no es aceptable, un proveedor de facturación con SFE ya homologado elimina este riesgo a cambio de un costo por documento.

### 4.2 Certificado digital

- Formato **PKCS#12 (`.pfx` / `.p12`)**, emitido por una entidad de certificación acreditada por INDECOPI.
- Titular: la **empresa emisora** (el RUC emisor, no el desarrollador del software).
- Guardar el archivo en **disco privado** (nunca `public/`), cifrado y con permisos restringidos.
- La clave privada requiere contraseña: cifrar el atributo con el cast `encrypted` de Laravel.
- **Vigilar la fecha de vencimiento**: un certificado vencido = el sistema entero no puede emitir. Alerta 60/30/15 días antes (§9).

### 4.3 Credenciales SOL

- **Usuario SOL secundario** dedicado al software (no usar el del titular ni el primario). Permite revocar sin afectar la operación de la persona.
- RUC + usuario + clave. Cifrados en reposo.

### 4.4 Registro de series

Las series deben estar autorizadas por SUNAT. Solicitar:

- Factura: `F001`, `F002`, … hasta 20 series por establecimiento.
- Boleta: `B001`…`B005` y luego `BB01`… (SUNAT permite series alfanuméricas a partir de la sexta).
- **Un punto de emisión por establecimiento (agencia).** Si hay N agencias operando, hay N puntos de emisión → **N juego de series**. Este es un detalle frecuente que se olvida y bloquea la producción.

### 4.5 Datos fiscales que deben estar listos

**Del emisor** (por empresa y por agencia/establecimiento):

- RUC validado con dígito verificador.
- Razón social exacta según ficha de SUNAT (registrada, **no** la comercial).
- Domicilio fiscal estructurado: calle, número, interior, ubigeo de 6 dígitos, código de establecimiento.
- Correo de recepción de comprobantes.

**Del receptor** (por cliente):

- Tipo de documento (**agregar RUC** al catálogo actual).
- Número de documento validado por longitud según tipo (RUC 11, DNI 8, CE 9 alfanumérico, pasaporte variable).
- Razón social o nombres según el tipo.
- Domicilio con ubigeo (obligatorio para factura; para boleta con DNI se puede emitir sin domicilio completo, pero se recomienda).
- Correo para envío del PDF.

### 4.6 Decisiones tributarias pendientes con el contador

Estos puntos **no son decidibles con código** y cambian el diseño:

1. **¿Los intereses de crédito prendario están gravados con IGV al 18%?** Es la premisa del diseño. Confirmar el tratamiento y si hay algún régimen especial.
2. **¿Aplica detracción?** Si el cliente tiene RUC y el importe supera el umbral de detracción, la operación debe emitirse en **modo Detracción** (`FormaPago` `P0xx`, código de detracción de SUNAT, y el pago se hace directo a SUNAT, no a la financiera). Esto **rompe el flujo de caja actual** porque el dinero no entra a la caja del asesor. Hay que decidir antes de la Fase 3.
3. **¿Aplica retención sobre los intereses?** El sistema hoy no tiene ningún concepto de retención. Si aplica, hay que agregar el registro de retenciones y el reporte correspondiente a SUNAT.
4. **¿Los descuentos concedidos (`cobros.descuento`) reducen la base?** Asumido sí (§3.1), a confirmar.
5. **Tratamiento de los créditos refinanciados.** El interés pagado en el predecesor queda en el predecesor y el sucesor arranca con capital nuevo. El plan lo asume así (cada crédito se factura por separado), a confirmar.
6. **¿Correlativo por establecimiento o único por empresa?** El plan asume **por establecimiento** (requisito SUNAT).

### 4.7 Dos estrategias posibles

| | **Opción A — Nativa (recomendada a mediano plazo)** | **Opción B — Proveedor SaaS** |
|---|---|---|
| Qué es | El sistema genera, firma y envía el XML directamente a SUNAT | El sistema genera el XML y lo envía a un proveedor (NubeTAX, Datafast, Apisales…), que habla con SUNAT |
| Homologación | **Requiere homologar nuestro software** (riesgo y tiempo) | El SFE del proveedor ya está homologado |
| Costo | Sin costo por documento | Costo por documento (~$0.01–0.10) |
| Dependencia | Ninguna externa (funciona sin internet, salvo el envío) | Requiere internet estable; el documento queda en un tercero |
| Certificado | Lo manejamos nosotros | Lo entregamos al proveedor |
| Idoneo | Si el volumen es alto (miles de comprobantes/mes) | Si el volumen es bajo o se quiere ir a producción rápido |

**Recomendación:** construir el **núcleo de dominio** (series, correlativo, base imponible, detalle, PDF, notas de crédito) **agnóstico del canal de envío**, y decidir el canal (A o B) al final. El 80% del desarrollo es común a ambas.

---

## 5. Diseño técnico

### 5.1 Ubicación y estructura

Nuevo módulo `app/Modules/Facturacion`, siguiendo la convención exacta del resto del sistema:

```
app/Modules/Facturacion/
├── Models/
│   ├── Comprobante.php
│   ├── ComprobanteDetalle.php
│   ├── ComprobanteEnvio.php
│   ├── ComprobanteBaja.php
│   ├── SerieComprobante.php
│   ├── ConfiguracionFacturacion.php
│   └── CredencialSunat.php
├── Services/
│   ├── ComprobanteService.php              # emitir, anular, nota de crédito
│   ├── BaseImponibleService.php            # cálculo desde crédito / venta
│   ├── SerieService.php                    # series y correlativos
│   ├── ComprobantePdfService.php           # representación impresa
│   └── Sunat/
│       ├── SunatClientInterface.php
│       ├── SunatSoapClient.php             # implementación SOAP (ext-soap)
│       ├── UblBuilder.php                  # XML UBL 2.1
│       ├── FirmanteDigital.php             # firma PKCS#12
│       ├── CdrParser.php                   # lectura de la respuesta
│       └── CatalogoSunat.php               # catálogos SUNAT (tipos doc, medios, unidades)
├── Jobs/
│   ├── EnviarComprobanteJob.php
│   └── EnviarResumenDiarioJob.php
├── Console/Commands/
│   ├── ComprobantesReintentarEnvio.php
│   └── ComprobantesEnviarResumenDiario.php
├── Http/{Controllers,Requests}/
├── Policies/
├── Events/
├── Notifications/
└── Exceptions/
    └── ComprobanteException.php
```

El cliente SUNAT va **detrás de una interfaz** (`SunatClientInterface`) porque: (a) permite `Http::fake()` / bind de un fake en tests, exactamente como ya hace `ConsultaDniService`; (b) permite cambiar a Opción B sin reescribir el dominio.

### 5.2 Modelo de datos

#### `comprobantes` — cabecera (el documento fiscal)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigIncrements | |
| `empresa_id` | FK empresas | `BelongsToTenant` |
| `agencia_id` | FK agencias nullable | Establecimiento / punto de emisión |
| `cliente_id` | FK clientes | |
| `origen_tipo` + `origen_id` | morph | `credito` \| `venta` \| `cobro`. Permite múltiples comprobantes por origen |
| `tipo_documento` | char(2) | `01` factura, `03` boleta, `07` nota de crédito |
| `serie` | char(4) | `F001`, `B001`, `BC01`… |
| `correlativo` | unsignedInteger | |
| `numero` | string(20) | `F001-00000123`, generado y único |
| `fecha_emision` | date | |
| `hora_emision` | time | |
| `moneda` | char(3) | `PEN` \| `USD` |
| `tipo_operacion` | string | `venta` \| `servicio` |
| `forma_pago` | string | `01` contado, `03` crédito, `P0xx` detracción |
| `medio_pago` | string nullable | `005` efectivo, `003` transferencia, `011` yape/plin |
| `subtotal` | decimal(12,2) | Base imponible |
| `descuento_global` | decimal(12,2) | |
| `igv` | decimal(12,2) | |
| `total` | decimal(12,2) | |
| `importe_letras` | string | Snapshot de `NumeroALetras::soles()` |
| `observaciones` | string nullable | Nota del XML (máx. 1000 car.) |
| `estado` | string | ver máquina de estados §5.8 |
| `codigo_seguridad` | char(4) nullable | Del CDR; se imprime en el PDF |
| `hash_cdr` | char(64) nullable | Digest SHA-256 del CDR |
| `cdr_codigo` | string nullable | `0` aceptado, `2000+` observaciones, `4000+` rechazo |
| `cdr_descripcion` | string nullable | |
| `cdr_digests` | json nullable | |
| `enviado_at` / `aceptado_at` | timestamp nullable | |
| `anulado_por` / `anulado_at` / `motivo_anulacion` | | |
| `nota_credito_de_id` | FK comprobantes nullable | Referencia para notas de crédito |
| `documento_origen_id` | FK comprobantes nullable | Comprobante que se anula |
| `generado_por` | FK users | |
| `xml_path` / `cdr_path` / `zip_path` / `qr_path` | string nullable | Disco **privado** |
| `timestamps` | | |
| índices | | `(empresa_id, fecha_emision)`, unique `(empresa_id, serie, correlativo)`, `(estado)` |

**Snapshot fiscal del emisor y del receptor.** El XML debe reflejar el estado de los datos **al momento de emitir**. Si mañana se corrige el RUC o la razón social de un cliente, los comprobantes ya emitidos no deben cambiar. Por eso `comprobantes` guarda copia de: `emisor_ruc`, `emisor_razon_social`, `emisor_domicilio_*`, `emisor_ubigeo`, `cliente_tipo_documento`, `cliente_numero_documento`, `cliente_nombre`, `cliente_direccion`, `cliente_ubigeo`. Sin esto, el PDF re-renderizado (que el sistema regenera al vuelo) mostraría datos distintos de los realmente enviados a SUNAT.

#### `comprobante_detalles` — líneas

`comprobante_id`, `orden`, `cantidad`, `codigo` (catálogo 08 de SUNAT), `descripcion`, `unidad_medida` (catálogo 03/UNEI), `valor_unitario`, `precio_unitario`, `descuento`, `valor_venta`, `igv`, `total`, `categoria_tributo` (`S` gravado, `G` exonerado, `E` gratuito), `porcentaje_igv`.

Para el caso de crédito, el detalle típico sería:

| # | Descripción | Categoría |
|---|---|---|
| 1 | Interés por crédito prendario C-000123 (período 12/08/2026 – 03/09/2026) | S |
| 2 | Interés por refrendo | S |
| 3 | Mora por atraso en cuotas | S |

#### `series_comprobantes`

`empresa_id`, `agencia_id` nullable, `tipo_documento`, `serie` (unique con `empresa_id`), `descripcion`, `estado` (`activa`/`inactiva`), `ultimo_correlativo`, `limite_numeracion` (asignado por SUNAT), `punto_emision`, `creado_por`.

#### `configuraciones_facturacion`

`empresa_id` (unique), `modo` (`beta`/`produccion`), `igv_porcentaje` (18.00), `moneda` (`PEN`), `interes_incluye_igv` (bool), `medio_pago_por_defecto`, `serie_boleta_defecto`, `serie_factura_defecto`, `permitir_bajo_umbral`, `activo`.

Espejo exacto de `configuraciones_venta`, con el patrón `resolverPara()`.

#### `credenciales_sunat`

`empresa_id` (unique), `usuario_sol` (encrypted), `clave_sol` (encrypted), `certificado_path` (disco privado), `certificado_password` (encrypted), `certificado_razon_social`, `certificado_vence_en`, `ultima_validacion_at`, `estado`.

#### `comprobante_envios` — bitácora

`comprobante_id`, `intento`, `estado` (`pendiente`/`enviado`/`aceptado`/`error`), `request_xml` (text), `cdr_codigo`, `cdr_descripcion`, `cdr_xml`, `excepcion`, `duracion_ms`, `created_at`.

> Permite auditar exactamente qué se envió en cada reintento. Es la evidencia que SUNAT pide cuando hay discrepancias.

### 5.3 Cálculo de la base imponible

`BaseImponibleService` — el corazón del módulo, y lo que más tests necesita.

```php
/**
 * Base imponible de un crédito: la suma del interés, la mora y los otros
 * conceptos cobrados, menos los descuentos. El capital nunca entra: es
 * devolución del principal, no ingreso de la financiera.
 *
 * @return array{detalle: list<array<string, mixed>>, base: string, igv: string, total: string}
 */
public function desdeCredito(Credito $credito): array
```

Reglas:

1. `cobros` con `credito_id = :id` **y `estado = 'registrado'`** (excluye anulados).
2. Para cada cobro se generan las líneas: interés (`cobros.interes`), mora (`cobros.mora` si > 0), y otros conceptos si existen.
3. `descuento` se aplica como **descuento global** del comprobante, distribuido proporcionalmente entre las líneas.
4. `vuelto` y `monto_pagado` **no** se usan: el interés ya viene desglosado.
5. Verificación de consistencia contra `cuotas_credito_prendario.monto_interes`; si la diferencia supera el umbral configurado, exigir confirmación de supervisor.
6. Si el crédito tiene **cadena de refinancing**, se factura solo su propio interés. El del predecesor ya se facturó con el predecesor.
7. Si la base resulta `0.00`, **no emitir**: es una condición de error, no un caso válido.

### 5.4 Series y correlativos (sin huecos)

El correlativo es el requisito técnico más delicado. **Un hueco en la numeración invalida la operación ante SUNAT.**

**Reglas de diseño:**

- El correlativo se consume **al emitir**, dentro de la misma transacción que inserta el comprobante.
- Si el envío a SUNAT falla, el comprobante **conserva su número** y se reintenta **con el mismo número**. Nunca se libera ni se reutiliza.
- Solo se comunica **baja** cuando un comprobante ya aceptado debe cancelarse por una causa válida (error en los datos, devolución, acuerdo comercial).
- Un documento que nunca llegó a enviarse y se descarta puede destruirse sin baja, **siempre que** su correlativo no se haya reservado… por eso la regla anterior (nunca liberar) es la que evita el problema: o se emite, o se comunica la baja.

**Implementación** (dentro de `DB::transaction`):

```php
$serie = SerieComprobante::query()->lockForUpdate()->findOrFail($serieId);
$serie->increment('ultimo_correlativo');
$correlativo = $serie->ultimo_correlativo;
```

`lockForUpdate()` es no-op en SQLite (el motor de tests, `phpunit.xml:26-27`), pero el patrón sigue siendo correcto en MySQL y los tests verifican la monotonía en serie.

**Asignación de serie por tipo de receptor:** la serie se elige según el comprobante a emitir, no la elige el usuario libremente, y debe estar habilitada para el establecimiento del actor.

### 5.5 Integración con SUNAT

**Endpoints SOAP** (`config/facturacion.php`):

| Entorno | WSDL |
|---|---|
| Pruebas (beta) | `https://e-beta.sunat.gob.pe/ol/billService?wsdl` |
| Producción | `https://e-apiserprod.sunat.gob.pe/ol/billService?wsdl` |

**Operaciones necesarias:**

| Operación | Para qué |
|---|---|
| `sendBill` | Envío individual del comprobante (facturas; boletas hasta 350/día). |
| `sendSummary` | **Resumen diario de boletas** y **comunicación de baja**. |
| `getStatus` | Estado del CDR (código, descripción, digest). |
| `sendPack` | Envío masivo (opcional, futuro). |
| `getCredit` | Verificar límite de documentos disponibles (recomendable: alerta antes de quedarse sin cupo). |

**Nombre del archivo** que SUNAT exige: `{RUC}-{TipoDoc}-{Serie}-{Correlativo}.xml`, p. ej. `20123456789-01-F001-00000123.xml`. En beta se usa el RUC emisor de prueba `20000000001`.

**Implementación de `SunatSoapClient`:**

- `ext-soap` + `ext-dom`. En `composer.json` como `suggest` y verificación en `config`/health check, porque en cPanel las extensiones suelen estar activas pero conviene detectarlo.
- El **WSDL no se cachea en el repo**: se resuelve en runtime y se cachea con `Cache::remember()`.
- El ZIP de respuesta (XML del CDR + PDF de representación) se descomprime y guarda en disco privado.
- Errores: mapeo del catálogo de errores de SUNAT a mensajes accionables en español (ver §12). Un comprobante rechazado nunca debe mostrar al usuario un código crudo.
- `timeout` de 30–60 s. Las respuestas de SUNAT pueden tardar.

### 5.6 Generación del XML (UBL)

`UblBuilder` construye el XML con `DOMDocument` y `DOMXPath`, siguiendo la estructura UBL 2.1 de SUNAT.

**Estructura mínima obligatoria:**

```
Invoice  (xmlns="http://schemas.sunat.gob.pe/Invoice/2/1")
├── ext:UBLExtensions → ext:UBLExtension → ext:UBLExtensionContent → sig:UBLDocumentSignatures
│       → sac:SignatureInformation → ds:Signature  [referenciado por cac:Signature/ID]
│   └── (ext2:UBLExtension → sac:QR)                 [cuando aplica]
├── cac:Signature            ID="SignatureSPID"
├── cbc:UBLVersionID        "2.1"
├── cbc:CustomizationID      "2.0"
├── cbc:ID                   "F001-00000123"
├── cbc:IssueDate / IssueTime
├── cbc:DueDate              (solo crédito)
├── cbc:InvoiceTypeCode      "01" | "03" | "07"
├── cbc:Note                 importe en letras + observaciones
├── cbc:DocumentCurrencyCode "PEN"
├── cac:AdditionalDocumentReference → cbc:ID   (tipo doc del receptor: 0/4/6/7/A)
├── cac:AccountingSupplierParty      → RUC, razón social, domicilio + ubigeo
├── cac:AccountingCustomerParty      → doc del receptor, nombre, domicilio + ubigeo
├── cac:PaymentMeans        → forma de pago, medio de pago
├── cac:PaymentTerms        → forma de pago / código de detracción
├── cac:TaxTotal → cac:TaxSubtotal   → categoría, %, monto
├── cac:LegalMonetaryTotal  → LineExtensionAmount, TaxInclusiveAmount, PayableAmount
└── cac:InvoiceLine (1..n)  → cantidad, valor unitario, descripción, impuestos
```

**Detalles que se olvidan y hacen que SUNAT rechace el documento:**

- El `ds:Signature` **va dentro de `ext:UBLExtensions`**, y el `cac:Signature` es solo la referencia por `ID="SignatureSPID"`.
- El **`Invoice` es la raíz**, envuelto en `ApplicationEnvelope` (que es el `SOAP-ENV:Body`).
- El **ubigeo** es un solo código de 6 dígitos (`cbc:ID="150101"`), no un código de distrito suelto.
- El nombre del receptor se debe partir según el tipo de documento: para RUC, `cac:PartyLegalEntity/cbc:RegistrationName` con la razón social completa; para personas naturales, `cac:PartyName/cbc:Name` con los nombres.
- El `cbc:InvoicedQuantity` con **3 decimales** y el `PriceAmount` con el formato SUNAT (`150.0000`).
- Toda línea necesita `cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount` **por línea**, además del total del documento.
- `Note` máximo 1000 caracteres.

**Pruebas de validez:** validar el XML generado contra el XSD oficial de SUNAT en los tests (`assertXmlStringEqualsXmlString` con el XSD cacheado en `tests/Fixtures/`). Es la única forma barata de no descubrir errores de esquema en producción.

### 5.7 Firma digital

`FirmanteDigital`:

1. Leer el `.pfx` del disco privado con `file_get_contents`.
2. `openssl_pkcs12_read($contenido, $datos, $password)`.
3. Firmar el `DigestValue` (SHA-1 del `cbc:ID`, según la especificación SUNAT) con `openssl_sign(..., OPENSSL_ALGO_SHA1)`.
4. Construir el nodo `ds:Signature` con `Reference URI=""`, `Transforms` (`enveloped-signature`, `c14n`), `DigestMethod`, `DigestValue`, `SignatureMethod`, `SignatureValue`.

**Riesgo real — verificado el 2026-10-06.** La especificación SUNAT usa tradicionalmente **SHA-1 / RSA-SHA1**. Se probó en el entorno de desarrollo y **SHA-1 funciona**: `openssl_digest('sha1', ...)` responde correctamente con OpenSSL 3.0.21. El error `80000003` que aparece al generar una clave RSA con `openssl_pkey_new()` en esta máquina es un problema de OpenSSL **en Windows**, no una restricción del algoritmo.

Queda pendiente:

- Confirmar en el **servidor cPanel real** (Linux, otra versión de OpenSSL). Es la única verificación que importa.
- `greenter/xmldsig` (v5.1.0, §5.12) ya resuelve la firma según la norma SUNAT, así que si apareciera el bloqueo, se ajusta la configuración de OpenSSL del servidor y no el código.
- Si el nivel de seguridad del sistema lo imposibilita, la salida es el canal externo (Opción B, §4.7).

**Validación previa obligatoria:** un comando `facturacion:validar-certificado` que firme un XML de prueba con las credenciales de la empresa y confirme que la cadena es válida y no está vencida.

### 5.8 Ciclo de vida del comprobante

```
                    ┌──────────┐
                    │ borrador │  (opcional: preparado, aún sin número)
                    └────┬─────┘
                         │ emitir (consume correlativo, genera XML + PDF)
                         ▼
                    ┌──────────┐
                    │ generado │  XML firmado, pendiente de envío
                    └────┬─────┘
                         │ enviar
              ┌──────────┼───────────────┬───────────────┐
              ▼          ▼               ▼               ▼
        ┌──────────┐ ┌──────────┐  ┌───────────┐  ┌──────────┐
        │ enviado  │ │  error   │  │ observado │  │ rechazado│
        └────┬─────┘ └────┬─────┘  └─────┬─────┘  └────┬─────┘
             │            │             │             │
             ▼            │ retry       │ (observado │ (no se puede
        ┌──────────┐      └─────────────┘  persiste) │  corregir: nota
        │ aceptado │                                  │  de crédito o baja)
        └────┬─────┘                                  │
             │                                        │
             ├──────────────► ┌──────────┐ ◄──────────┘
             │                │  anulado  │
             │                └──────────┘
             │ (baja comunicada | nota de crédito emitida)
             ▼
      ┌─────────────┐
      │ sustituido  │  (existe una nota de crédito que lo corrige)
      └─────────────┘
```

**Transiciones y efectos:**

| Transición | Quién | Efecto |
|---|---|---|
| `borrador` → `generado` | Usuario con `facturaciones.crear` | Consume correlativo, valida receptor y umbral, genera XML y PDF. |
| `generado` → `enviado` | Job o acción manual | Envía a SUNAT, guarda respuesta. |
| `enviado` → `aceptado` | CDR código `0` | Guarda digest, código de seguridad, genera QR real, archiva CDR. Notifica. |
| `enviado` → `observado` | CDR `2xxx` | **Persiste.** El comprobante es válido; se notifica que hay observaciones a corregir en el próximo. |
| `enviado` → `rechazado` | CDR `4xxx` | **No persiste.** Se puede corregir y reemitir… pero **no** con el mismo número: se necesita **nota de crédito + nueva factura**, porque el número ya fue consumido. Este es el punto más delicado del flujo y debe estar implícito en la UI. |
| `enviado` → `error` | Falla técnica | Reintento automático con backoff. Conserva el número. |
| `aceptado` → `anulado` | Usuario con `facturaciones.anular` | Comunicación de baja a SUNAT (`sendSummary`). Debe hacerse dentro del plazo permitido. |
| `aceptado` → `sustituido` | Emisión de nota de crédito | El original no se borra; queda referenciado. |

**Anulación.** Dos caminos, y la UI debe distinguirlos claramente:

- **Comprobante no comunicado** (en `generado` o `rechazado`): se marca `anulado` localmente. No hay comunicación.
- **Comprobante ya aceptado**: **comunicación de baja** obligatoria ante SUNAT dentro del plazo legal (verificar: normalmente 7 días calendario desde la emisión). Fuera de plazo, la única vía es **nota de crédito**.

**Nota de crédito** (`07`): documento nuevo que referencia al original, por el total o por un monto parcial. Sirve para devoluciones, quitas, descuentos posteriores y correcciones de un comprobante ya aceptado. Debe referenciar `documento_origen_id` y su número.

**Plazo para notas de crédito sobre boletas:** verificar en la normativa vigente; hay restricciones de plazo que difieren entre factura y boleta. **No implementarlo sin confirmar.**

### 5.9 PDF del comprobante

`ComprobantePdfService`, Blade en `resources/views/modules/facturacion/documentos/comprobante.blade.php`, siguiendo el estilo de casa (documento HTML autónomo, `<style>` inline, `DejaVu Sans`, entidades HTML en lugar de acentos).

Contenido **obligatorio**:

- Denominación, RUC, razón social, domicilio fiscal del emisor.
- Tipo de documento, **serie-correlativo** en formato `F001-00000123`.
- Fecha y hora de emisión.
- Datos del receptor (tipo + número + nombre / razón social).
- Detalle de líneas: cantidad, unidad, descripción, valor unitario, importe.
- Subtotal, IGV (%), total.
- **Importe total en letras.**
- **Código de seguridad** en formato `*ABCD*`.
- **QR** (cuando aplique): `emisor|tipoDoc|serie|correlativo|total|hashCDR|fechaEmision|codSucursal`.
- Leyenda: *"Representación impresa de un comprobante electrónico"*.
- Posición de "Representación impresa" o el trademark cuando corresponda.

**QR:** `Endroid\QrCode` (ya instalado) → data-URI, igual que los stickers de crédito. **El QR solo puede generarse tras el CDR aceptado**, porque necesita el hash del CDR. Antes de eso se emite un QR de estado o simplemente no se imprime.

### 5.10 Colas y reintentos

El envío a SUNAT **debe** ser asíncrono:

- `EnviarComprobanteJob implements ShouldQueue`, `$tries = 5`, `backoff()` progresivo, `timeout = 120`.
- La API responde `202 Accepted` con el comprobante en `generado` y el frontend muestra "Enviando…". El CDR llega por WebSocket (`ComprobanteActualizado`) y por notificación interna.
- `comprobantes:reintentar-envio` (programado cada 15 min) recupera los que quedaron en `error` o `generado` huérfano.
- `comprobantes:enviar-resumen-diario` (programado a las 23:00, o configurable) agrupa las boletas aceptadas del día y envía el resumen. **Debe ser idempotente por fecha.**

**Requisito de infraestructura:** hay que garantizar `queue:work` corriendo en producción. Hoy el proyecto lo evita a propósito (`NotificacionService`), así que esto debe documentarse en `COMANDOS.md` y agregarse al checklist de despliegue. Sin worker, todo queda colgado en `generado`.

### 5.11 Seguridad y multi-tenancy

- Todos los modelos con `BelongsToTenant`. El scope da **404** (no 403) ante un id de otra empresa — comportamiento ya probado en `tests/Feature/Tenancy/TenantIsolationTest.php`.
- Credenciales cifradas en reposo (`encrypted` casts). **No** en `config/` (es global a la instancia) ni en variables de entorno (son por empresa).
- Certificado en disco **privado**. Nunca `public/`. Añadir un disco `privado` a `config/filesystems.php`.
- La clave SOL nunca sale por la API: los endpoints de configuración solo exponen `***` y permiten escribir.
- `Policy` con el patrón `before()` de superusuario `sistemas` + escalera de `administrador_general` / `administrador_agencia`, replicando `ConfiguracionCreditoPolicy`.
- **La API nunca permite editar un comprobante en `aceptado`**. Solo anotar observaciones o emitir nota de crédito.

### 5.12 Librería de integración: decisión

SUNAT no publica un SDK oficial: solo el WSDL y los XSD. La opción más sólida hoy es el conjunto de paquetes de **Greenter**, que es el estándar de facto en Perú (369 estrellas, 50 mil descargas, MIT, mantenido activo).

**Instalar los paquetes sueltos, nunca el metapaquete:**

```bash
composer require greenter/xmldsig:^5.1 greenter/xml:^5.3 greenter/ws:^5.3 greenter/xcodes:^5.3 greenter/xml-parser:^5.3
```

Verificado con `composer require --dry-run` contra este proyecto (Laravel 12, PHP 8.2): **resuelve limpio — 9 paquetes nuevos, 0 conflictos, 0 actualizaciones, sin avisos de seguridad.**

| Paquete | Responsabilidad | Última versión |
|---|---|---|
| `greenter/xmldsig` | **Firma digital del XML según norma SUNAT** | v5.1.0 (2026-10-04) |
| `greenter/xml` | Generación del XML UBL 2.1 | v5.3.0 (2026-06-08) |
| `greenter/ws` | SOAP: `sendBill`, `sendSummary`, `getStatus` | v5.3.0 (2023-05-03) |
| `greenter/xcodes` | Catálogo de códigos de error de SUNAT | v5.3.0 |
| `greenter/xml-parser` | Lectura del CDR, incluido el ZIP | v5.3.0 |

**Por qué los paquetes sueltos y no `greenter/greenter`:** el metapaquete arrastra `mikehaertl/phpwkhtmltopdf`, que exige el **binario `wkhtmltopdf` instalado en el servidor** — inviable en un hosting cPanel compartido y no instalable desde el proyecto. También traería `twig/twig` y `bacon/bacon-qr-code`, redundantes: el PDF lo seguimos haciendo con `barryvdh/laravel-dompdf` (ya instalado) y el QR con `endroid/qr-code` (ya instalado). Con los paquetes sueltos, lo único que se suma es Twig, que `greenter/xml` usa internamente para sus plantillas UBL.

**Excepciones al patrón del proyecto, justificadas:**

- **Twig**: `greenter/xml` lo exige. No se puede evitar, pero queda encapsulado dentro de la librería — las plantillas UBL de SUNAT no se tocan.
- **`greenter/xcodes`**: resuelve el mapeo de errores que en §5.5 estaba planteado como trabajo propio.

**Lo que NO se usa y por qué:**

| Alternativa | Motivo del descarte |
|---|---|
| `greenter/greenter` (metapaquete) | Exige el binario `wkhtmltopdf` en el servidor. |
| `codersfree/laravel-greenter` | Wrapper personal de un tercero sobre Greenter, no oficial. |
| `tecactus/sunat-php` | 2 estrellas; parece un fork de Greenter sin mantenimiento propio. |
| `elpandape/quipu-lite` | 101 descargas, cero adopción, "unofficial". Demasiado riesgo. |
| `giansalex/peru-consult` | **Abandonado**, y además es solo consulta de RUC/DNI, no emite comprobantes. Sirve como referencia para la Fase 1. |
| `luecano/numero-a-letras` | El proyecto ya tiene `NumeroALetras::soles()`, testeado y en uso. |
| `aeunius/laravel-catalogos-sunat` | 10 descargas. Los ~15 valores de catálogo que necesitamos van en `CatalogoSunat` como constantes, no en un paquete. |

**Interfaz obligatoria alrededor del cliente SOAP.** `greenter/ws` usa `ext-soap` internamente, y eso **no se puede falsear con `Http::fake()`** como sí se hacía con `ConsultaDniService`. Por eso `SunatClientInterface` no es opcional: es lo que permite `app()->bind(SunatClientInterface::class, FakeSunatClient::class)` en los tests, sin tocar internet. También es lo que deja abierta la Opción B del §4.7 sin reescribir el dominio.

**Momento de instalación:** las Fases 1, 2 y 3 (datos fiscales, núcleo de dominio, documento interno) **no necesitan ninguna dependencia nueva**. Los paquetes se instalan al iniciar la Fase 4.

---

## 6. Integración con los módulos existentes

> ### Choque de nombres con `caja_movimientos.serie` / `.numero`
>
> La migración `2026_10_06_005212_add_serie_numero_boleta_to_caja_movimientos_table.php` agrega `serie` + `numero` a `caja_movimientos`, con índice único `caja_movimientos_boleta_unica`, para el módulo Ingresos/Egresos.
>
> **Ese par identifica una boleta de un tercero que la empresa recibe** (la boleta de la estación de servicio donde se compró combustible). **El módulo de facturación emite el comprobante, no lo recibe.** Listas idénticas, significado opuesto:
>
> | | `caja_movimientos.serie/numero` | `comprobantes.serie/numero` |
> |---|---|---|
> | Qué es | Boleta de un proveedor | Comprobante que emitimos |
> | Quién lo firma | El proveedor | Nosotros, con nuestro certificado |
> | Va a SUNAT | No | Sí |
> | Para qué sirve | No duplicar el registro de un gasto | Documento fiscal con valor legal |
>
> **Regla: el módulo de facturación nunca reutiliza esas columnas.** Si alguna vez hay que vincular un comprobante emitido a un movimiento de caja, se hace con una **FK `comprobante_id`**, jamás por `serie`/`numero`. El índice único de `caja_movimientos` no debe intervenir nunca en la numeración de los comprobantes que emitimos.

### 6.1 Desde crédito (caso principal)

```
CreditoService::liquidar()  /  CreditoService::confirmarActaDevolucion()
        │
        ▼  estado = liquidado | liquidado_pendiente
   Evento CreditoActualizado  (ya existe)
        │
        ▼
   Frontend: "Créditos pendientes de facturar"
        │  el usuario selecciona uno o varios
        ▼
   Facturacion\ComprobanteService::emitirDesdeCredito()
        ├─ BaseImponibleService::desdeCredito()   (Σ cobros.interes + mora − descuento)
        ├─ valida receptor (RUC → factura; DNI/CE → boleta)
        ├─ valida umbral SUNAT de boleta
        ├─ SerieService::siguienteCorrelativo()    (lockForUpdate)
        ├─ UblBuilder → XML → FirmanteDigital
        ├─ ComprobantePdfService → PDF + QR (tras CDR)
        └─ EnviarComprobanteJob → sendBill / sendSummary
```

Puntos de integración concretos:

- **`CreditoService::anularCobro()`**: si el crédito ya tiene un comprobante `aceptado`, la anulación del cobro debe **bloquearse** y exigir una nota de crédito. Hoy `anularCobro()` no sabe nada de facturación → hay que añadir la guarda.
- **`CajaService` / `CajaMovimiento`**: la factura **no** mueve caja. El dinero ya se cobró. Debe quedar explícito para que nadie lo duplique.
- **`ReporteFlujoCajaService`**: las filas con `concepto_id` nulo se clasifican como "Cobranza". La facturación no debe crear movimientos de caja, así que no hay doble conteo — pero conviene verificarlo con un test.
- **Créditos `diario`**: el estado terminal es `liquidado` directo. También deben ser facturables.

### 6.2 Desde venta de bienes

El módulo `Venta` vende artículos con precio único y sin desglose de impuestos (`ventas.precio_venta` es un monto total). Para facturar ventas:

- Agregar `igv`, `subtotal`, `total` a `ventas` y `pagos_venta` **o** calcular el desglose en el momento de emitir a partir del precio y de `interes_incluye_igv`-equivalente (en venta normalmente el precio **sí** incluye IGV → `subtotal = precio / 1.18`).
- Emitir en `crearContado()` / al quedar `pagada` (`VentaService::marcarPagada()`, línea 384).
- Corregir de paso: `registrarPago()` no despacha `CajaActualizada`, y `cancelarInterna()` no revierte el `CajaMovimiento`. Con facturación encima, esos defectos se vuelven visibles para el cliente.
- Implementar la anulación de `pagos_venta` (los campos existen, ningún servicio los usa).

**Decisión de alcance:** la venta de bienes es un segundo flujo. Si el volumen es bajo, conviene **dejarlo para una fase posterior** y emitir en la Fase 6 solo crédito. No bloquear el go-live por esto.

### 6.3 Desde cobros de caja / conceptos sueltos

Ingresos de caja con `concepto_id` que representen una venta gravable (por ejemplo, venta de un bien no registrado en `ventas`). Se permite emisión manual desde el propio módulo de caja. **Opcional**, no bloqueante.

---

## 7. API propuesta

Todas bajo `routes/api.php`, con `auth:sanctum`, nombradas, y respondiendo con el sobre `{success, message, data}` de `ApiResponse`.

**Series**
```
GET    /api/facturacion/series                  index
POST   /api/facturacion/series                  store
PUT    /api/facturacion/series/{serie}          update
DELETE /api/facturacion/series/{serie}          destroy
```

**Configuración**
```
GET    /api/facturacion/configuracion           show
PUT    /api/facturacion/configuracion           update
```

**Credenciales SUNAT**
```
GET    /api/facturacion/credenciales            show   (clave y password enmascaradas)
POST   /api/facturacion/credenciales            store  (sube el .pfx)
POST   /api/facturacion/credenciales/validar    validar certificado + clave
DELETE /api/facturacion/credenciales            destroy
```

**Créditos facturables** (la pantalla pedida en `APUNTES.md`)
```
GET  /api/facturacion/creditos-pendientes       index   (liquidado/liquidado_pendiente sin comprobante)
GET  /api/facturacion/creditos/{credito}/preview         (base imponible, detalle, tipo de comprobante sugerido)
```

**Comprobantes**
```
GET    /api/facturacion/comprobantes                    index  (filtros: fecha, serie, tipo, estado, cliente, agencia)
POST   /api/facturacion/comprobantes                    store  (emisión manual / desde crédito)
POST   /api/facturacion/comprobantes/emitir-creditos    store  (emisión masiva de créditos seleccionados)
GET    /api/facturacion/comprobantes/{comprobante}      show
POST   /api/facturacion/comprobantes/{comprobante}/enviar      reintento manual
POST   /api/facturacion/comprobantes/{comprobante}/anular      comunicación de baja
POST   /api/facturacion/comprobantes/{comprobante}/nota-credito  emitir NC
GET    /api/facturacion/comprobantes/{comprobante}/pdf          PDF (stream)
GET    /api/facturacion/comprobantes/{comprobante}/xml          XML
GET    /api/facturacion/comprobantes/{comprobante}/cdr          CDR (XML o ZIP)
```

**Notas de crédito**
```
GET  /api/facturacion/notas-credito               index
POST /api/facturacion/notas-credito               store
```

**Resumen diario**
```
GET  /api/facturacion/resumen-diario/{fecha}      show
POST /api/facturacion/resumen-diario/{fecha}/enviar
```

**Reportes**
```
GET /api/facturacion/reportes/emitidos            (boletas emitidas / libro de ventas, Excel)
GET /api/facturacion/reportes/por-estado          (aceptados, observados, rechazados, anulados)
GET /api/facturacion/reportes/anulados
GET /api/facturacion/reportes/notas-credito
GET /api/facturacion/reportes/por-cliente
```

**Dashboard**
```
GET /api/facturacion/resumen                      (contadores del día/mes, por serie, por estado)
```

---

## 8. Permisos y roles

Añadir a `PermissionSeeder::PERMISSIONS`:

```
facturaciones.ver
facturaciones.crear
facturaciones.anular
facturaciones.emitir_nota_credito
facturaciones.descargar
facturaciones.series.ver
facturaciones.series.editar
configuraciones_facturacion.ver
configuraciones_facturacion.editar
```

Distribución sugerida:

| Permiso | administrador_general | administrador_agencia | supervisor | asesor | secretaria | peinadora |
|---|---|---|---|---|---|---|
| `facturaciones.ver` | ✔ | ✔ | ✔ | ✔ (propios) | ✔ | — |
| `facturaciones.crear` | ✔ | ✔ | ✔ | — | — | — |
| `facturaciones.anular` | ✔ | — | — | — | — | — |
| `facturaciones.emitir_nota_credito` | ✔ | — | — | — | — | — |
| `configuraciones_facturacion.editar` | ✔ | — | — | — | — | — |

`sistemas` recibe todo por `syncPermissions(Permission::all())`.

También añadir el módulo toggleable en `PermissionSeeder::MODULOS` y `ROLE_MODULOS`:

```php
['key' => 'facturacion', 'nombre' => 'Facturación electrónica', 'grupo' => 'general'],
```

Esto es útil: una empresa que aún no tiene certificado puede tener el módulo apagado sin código.

---

## 9. Alertas y notificaciones

| Alerta | Cuándo | Canal |
|---|---|---|
| Certificado por vencer | 60 / 30 / 15 días antes | Notificación interna + email al administrador |
| Certificado vencido | Al momento | Notificación + banner en la UI + bloqueo de emisión |
| Comprobante aceptado | Inmediato | Notificación interna + WebSocket |
| Comprobante observado | Inmediato | Notificación (con el detalle de las observaciones) |
| Comprobante rechazado | Inmediato | Notificación + WebSocket |
| Resumen diario fallido | Al fallar | Notificación al administrador general |
| Resumen diario enviado | Al enviar | Notificación |
| Cupo de documentos por agotar | Cuando `getCredit` reporta un saldo bajo | Notificación |
| Antigüedad de pendientes | Créditos `liquidado` sin facturar > N días | Notificación diaria (es el "alerta para emitir facturación diario" de `APUNTES.md`) |

Comandos programados en `routes/console.php`:

```php
Schedule::command('comprobantes:reintentar-envio')->everyFifteenMinutes();
Schedule::command('comprobantes:enviar-resumen-diario')->dailyAt('23:00');
Schedule::command('facturacion:validar-credenciales')->weekly();
Schedule::command('facturacion:alerta-certificado')->daily();
```

---

## 10. Frontend (SPA React)

Módulo nuevo con lazy-loading, siguiendo el patrón de los demás módulos:

| Vista | Contenido |
|---|---|
| `Facturacion/Comprobantes` | Listado con filtros (fecha, serie, tipo, estado, cliente, agencia), badges de estado en vivo, descarga de PDF/XML/CDR, reintento, anular, nota de crédito. |
| `Facturacion/CreditosPendientes` | **La pantalla pedida por el cliente.** Lista de créditos `liquidado` / `liquidado_pendiente` sin facturar, con selección múltiple, preview de la base imponible por crédito, y emisión masiva. |
| `Facturacion/Emitir` | Wizard: cliente → conceptos → tipo de comprobante → detalle → confirmación. |
| `Facturacion/Series` | CRUD de series con correlativo actual, estado y límite de SUNAT. |
| `Facturacion/Configuracion` | IGV, moneda, `interes_incluye_igv`, modo beta/producción, series por defecto. |
| `Facturacion/Credenciales` | Upload del `.pfx`, usuario/clave SOL, estado y vencimiento del certificado, botón "validar". **Nunca muestra la clave.** |
| `Facturacion/ResumenDiario` | Estado del resumen del día, reenvío, detalle de boletas incluidas. |
| `Facturacion/Reportes` | Reportes de boletas emitidas y export Excel. |
| `Facturacion/Dashboard` | Contadores del día y del mes, por serie, por estado, tasa de rechazo. |

Componentes nuevos: `EstadoComprobanteChip`, `QrComprobante`, `SelectorSerie`, `UploadCertificado`, `DetalleLineasFactura`.

Integración en tiempo real: escuchar `comprobante.actualizado` en `App.Models.User.{id}` para actualizar el estado sin recargar.

---

## 11. Riesgos

| # | Riesgo | Impacto | Mitigación |
|---|---|---|---|
| 1 | **La homologación SUNAT rechaza el software** | Bloquea el go-live | Iniciar el trámite temprano (§4.1). Tener el canal externo (Opción B) como plan de contingencia. |
| 2 | **El servidor (cPanel) no soporta la firma SHA-1** | La firma no funciona en producción | **Riesgo bajo:** verificado el 2026-10-06 que SHA-1 funciona en el entorno de desarrollo (OpenSSL 3.0.21). Falta confirmar en el cPanel real, que es la única verificación que cuenta. Si falla, ajustar el nivel de seguridad de OpenSSL del servidor o usar el canal externo (Opción B). |
| 3 | **Faltan extensiones PHP** (`ext-soap`, `ext-dom`, `ext-openssl`) | El módulo no arranca | Verificado el 2026-10-06: `soap`, `dom`, `openssl`, `simplexml`, `xml`, `zip`, `curl`, `bcmath`, `mbstring` e `intl` están **todas OK** en desarrollo. Falta confirmar en cPanel y documentar en `COMANDOS.md`. |
| 4 | **Huecos en el correlativo** por concurrencia | Rechazo de SUNAT | `lockForUpdate` + nunca liberar el número + test de concurrencia. |
| 5 | **Sin internet en el servidor** | Comprobantes acumulados en `generado` | Reintentos automáticos + alerta visible. |
| 6 | **`queue:work` no corre en producción** | Nada se envía nunca | Requisito explícito de despliegue + monitoreo. |
| 7 | **La base imponible no coincide con lo esperado** (descuentos, refinancing) | Facturación incorrecta | Reporte de consistencia crédito a crédito + confirmación de supervisor + validación del contador. |
| 8 | **El cliente edita sus datos después de emitir** | PDF con datos distintos al XML | Snapshot fiscal en `comprobantes`. |
| 9 | **Se anula un cobro después de facturar** | Inconsistencia grave | Guarda en `anularCobro()` que obliga a nota de crédito. |
| 10 | **Umbral de boleta superado** | Infracción del cliente | Validación previa con advertencia y, si se desea, bloqueo configurable. |
| 11 | **Detracción obligatoria** | El dinero no entra a la caja | Resolver con el contador **antes** de la Fase 3 (§4.6). |
| 12 | **Alta volumetría** de comprobantes | Tiempo de respuesta | Emisión masiva + cola. |
| 13 | **Cambios normativos de SUNAT** | XML inválido | Catálogos y umbrales en configuración, no en código. |
| 14 | **Clientes sin RUC que necesitan factura** | No se puede emitir | Mejora del cadastro de clientes (Fase 1) |

---

## 12. Puntos a verificar con SUNAT

> Esta sección lista lo que **debe confirmarse contra la normativa vigente** antes de dar por cerrado el diseño. Ninguna de estas cifras debe considerarse definitiva sin validación.

1. **Versión del estándar XML.** UBL 2.1 es el vigente para SUNAT desde 2021; 2.0 quedó obsoleta. Confirmar la versión exacta del namespace y del `CustomizationID` que acepta el endpoint que se use.
2. **Algoritmo de firma.** Confirmar `DigestMethod` / `SignatureMethod` esperados (tradicionalmente SHA-1 / RSA-SHA1) y si SUNAT ya acepta algoritmos más fuertes.
3. **Umbral de identificación de personas naturales** en boletas (S/ 700 diarios / S/ 1,000 por comprobante) y si sigue vigente.
4. **Plazo para comunicación de baja** de un comprobante electrónico ya comunicado.
5. **Plazo y condiciones para notas de crédito sobre boletas** (y su diferencia con facturas).
6. **Plazo máximo de envío** de la factura (individual) y del resumen diario de boletas.
7. **Máximo de comprobantes por resumen diario** y por envío individual.
8. **Códigos de los catálogos vigentes**: tipo de documento del receptor, medio de pago, unidad de medida, categoría de tributo, forma de pago, tipo de comprobante.
9. **Tratamiento tributario del interés** de créditos prendarios (§4.6.1) y si el software debe aplicar algún criterio especial.
10. **Régimen de detracción y retención** aplicable a la operación (§4.6.2, §4.6.3).
11. **Catálogo de errores vigente** para el mapeo de mensajes (§5.5).
12. **Contenido exacto del QR** y obligatoriedad según el modo (`Venta` vs. `Detracción`).
13. **Requisitos de la homologación**: documentación a entregar y evidencia funcional que se solicitará.

> Descargar y versionar dentro del repositorio (o al menos referenciar la URL oficial) el **XSD de SUNAT** y el **catálogo de errores**, para poder validar los XML en los tests.

---

## 13. Plan de fases

Estimaciones en **jornadas de un desarrollador backend con conocimiento del sistema**. El frontend se avanza en paralelo.

| Fase | Contenido | Esfuerzo | Bloqueante |
|---|---|---|---|
| **0. Requisitos y riesgos técnicos** | Verificar extensiones PHP y firma en el servidor real. Adquirir certificado. Generar clave SOL. Solicitar series. Resolver las 6 decisiones tributarias con el contador. Iniciar trámite de homologación. | 2–5 días (paralelo) | **Sí** — bloquea todo el resto |
| **1. Datos fiscales** | RUC en `clientes` + validación por tipo. Consulta de RUC (extender `ConsultaDniService`). Domicilio fiscal estructurado de `empresas` y `agencias` (ubigeo, calle, punto de emisión). Validación de RUC de empresa. Snapshot de datos de contacto para envío del PDF. | 4–6 días | No |
| **2. Núcleo del módulo** | Migraciones, modelos, factories. `configuraciones_facturacion` + `credenciales_sunat` + `series_comprobantes`. `SerieService` con correlativo transaccional. `BaseImponibleService`. Enums y catálogos SUNAT. Policies, permisos, rutas. **Sin dependencias nuevas.** | 6–8 días | No |
| **3. Documento interno** | `ComprobanteService::emitirDesdeCredito()`. Detalle de líneas. PDF con toda la información obligatoria, código de seguridad y QR simulado. Pantalla de créditos pendientes de facturar. Tests de base imponible. **Sin dependencias nuevas.** | 6–8 días | No |
| **4. Integración SUNAT (beta)** | **Instalar los paquetes `greenter/*` (§5.12).** `UblBuilder`. `FirmanteDigital`. `SunatClientInterface` + `SunatSoapClient` (`sendBill`, `getStatus`). Parseo del CDR. Job de envío + reintentos. QR real tras el CDR. Validación contra XSD en tests. | 7–10 días | Fase 0 |
| **5. Ciclo completo** | Resumen diario. Comunicación de baja. Notas de crédito. Anulación. Manejo de observados y rechazados. Alertas. Comandos programados. | 6–8 días | Fase 4 |
| **6. Casos de uso** | Emisión desde crédito (todos los tipos: prendario, diario, vehicular, hipotecario). Emisión desde venta *(decisión de alcance)*. Integración con `anularCobro`. | 5–8 días | — |
| **7. Reportes y dashboard** | Boletas emitidas (Excel, formato libro de ventas). Por estado, anulados, notas de crédito, por cliente. Contadores. | 3–5 días | — |
| **8. Homologación** | Pruebas funcionales en beta con casos reales. Documentación. Presentación a SUNAT. Correcciones. | 5–10 días (paralelo) | **Sí** — bloquea el go-live |
| **9. Go-live** | Series de producción. Cutover en ventana sin operación. Capacitación al personal. Monitoreo intensivo la primera semana. | 2–3 días | Fases 0 y 8 |

**Total estimado: 8–10 semanas de calendario**, con 5–7 semanas de desarrollo backend y 2–3 de frontend en paralelo. El camino crítico **no es el código**: es el certificado y la homologación.

---

## 14. Checklist de verificación SUNAT

Para validar el desarrollo antes de pedir la homologación:

- [ ] El XML generado valida contra el XSD oficial de SUNAT.
- [ ] La firma digital es válida y la cadena del certificado es correcta.
- [ ] El comprobante es aceptado en beta con el RUC de prueba.
- [ ] El PDF de la representación contiene todos los datos obligatorios.
- [ ] El QR es legible y su contenido coincide con el CDR.
- [ ] El código de seguridad impreso coincide con el del CDR.
- [ ] El importe en letras coincide con el importe numérico.
- [ ] Los correlativos son correlativos, sin huecos, bajo concurrencia.
- [ ] La comunicación de baja funciona.
- [ ] La nota de crédito funciona y enlaza con el original.
- [ ] El resumen diario se genera y se acepta.
- [ ] Los errores de SUNAT se muestran traducidos y accionables.
- [ ] Un comprobante con receptor sin RUC y con monto excedido está bloqueado.
- [ ] El cálculo del IGV cuadra con la configuración de `interes_incluye_igv`.
- [ ] El total facturado de un crédito equivale a la suma de sus intereses cobrados, y **no incluye** el capital.

---

## 15. Resumen de decisiones tomadas en este plan

| Decisión | Elección | Motivo |
|---|---|---|
| Alcance | Facturación electrónica SUNAT completa | Requisito del cliente. |
| Qué se factura | Solo interés, mora y conceptos; **nunca** el capital | El capital es devolución del principal. Definido por el cliente. |
| Cuándo | Al cancelar el crédito (`liquidado` / `liquidado_pendiente`) | Definido por el cliente. Emisible antes del acta para no bloquear el cobro documental. |
| Fuente de la base | Suma de `cobros` en estado `registrado` | Coincide con el ingreso en caja y el Libro de Ventas. |
| Descuentos | Restan de la base | A confirmar con el contador. |
| Boleta vs factura | Automático según el documento del receptor | Evita error operativo. |
| IGV | 18%, con `interes_incluye_igv` configurable | El interés normalmente incluye IGV. |
| Correlativo | Reservado al emitir, `lockForUpdate`, nunca liberado | Los huecos invalidan la operación. |
| Anulación | Depende del estado: local si no se texasó, baja o nota de crédito si ya se aceptó | Es lo que exige SUNAT. |
| Arquitectura | `SunatClientInterface` con implementación SOAP | Testeable y deja abierta la Opción B. |
| Librería | `greenter/*` (paquetes sueltos: `xmldsig`, `xml`, `ws`, `xcodes`, `xml-parser`) | Estándar de facto en Perú, MIT, mantenido. Verificado que resuelve sin conflictos con Laravel 12. El metapaquete se descarta porque exige el binario `wkhtmltopdf`. |
| PDF del comprobante | `barryvdh/laravel-dompdf` (ya instalado) | No se agrega `greenter/report`: requiere `wkhtmltopdf`. |
| QR | `endroid/qr-code` (ya instalado) | No se agrega `bacon/bacon-qr-code`. |
| `caja_movimientos.serie/numero` | **No se reutiliza** | Es la boleta de un tercero que la empresa recibe, no el comprobante que emitimos. Vincular siempre por FK `comprobante_id`. |
| Envío | Asíncrono por cola, con reintentos | No bloquear la UX del cajero. |
| Snapshots fiscales | Guardados en el comprobante | Los datos ya enviados no pueden cambiar. |
| Módulo toggleable | Sí, con `ModuloService` | Empresas sin certificado pueden apagarlo. |
| Sucesores / refinancing | Cada crédito se factura por su propio interés | El predecesor ya facturó lo suyo. |