# Informe técnico del proyecto — UMAX / CREDIMAS ERP

> Documento base para armar el informe de precio / cotización.
> Fecha de corte: 2026-08-31.

---

## 1. Resumen ejecutivo

**UMAX** (marca comercial **CREDIMAS Perú**) es un **ERP web a medida para una financiera de créditos prendarios** (casa de empeños / préstamos con garantía de bienes). El sistema cubre de punta a punta la operación de una red de agencias:

- Gestión de **empresas y agencias** (multi-empresa / multi-sucursal).
- **Usuarios, roles y permisos** granulares con jerarquía organizacional.
- Registro y verificación de **clientes** (con consulta automática de DNI contra RENIEC vía API de terceros).
- Registro y valorización de **bienes en garantía** (con fotos y video).
- **Ciclo completo del crédito prendario**: solicitud → aprobación → firma de contratos → desembolso → cronograma de cuotas → refrendo / adenda / liquidación → devolución de bienes → venta del bien si entra en mora.
- **Tesorería completa**: bóvedas por agencia, cajas por usuario, ciclos diarios de apertura/cierre, billetajes (traspaso bóveda↔caja), cuentas bancarias, conciliación bancaria, movimientos de ingreso/egreso con conceptos y comprobantes.
- **Tienda virtual pública** para la venta de bienes rematados.
- **Reportería** de movimientos de dinero.
- **Notificaciones en tiempo real** (WebSockets) entre asesores y supervisores.
- **Generación de documentos PDF** (contrato, declaración jurada, acta de devolución, ficha de fotos, cronograma).

Arquitectura desacoplada: **API REST en Laravel 12** + **SPA en React 19**, desplegadas en dominios independientes.

---

## 2. Arquitectura general

```
┌─────────────────────────┐        HTTPS / JSON         ┌──────────────────────────┐
│  SPA React 19 (Vite)    │ ─────────────────────────►  │  API REST Laravel 12     │
│  erpdh.credimasperu.com │ ◄─────────────────────────  │  erpbk.credimasperu.com  │
│  (nginx, estático)      │                             │  (PHP 8.2, cPanel)       │
└───────────┬─────────────┘        WebSocket (WSS)       └────────────┬─────────────┘
            └──────────────────────────────────────────────────────────┘
                         Laravel Reverb (broadcasting en tiempo real)
                                          │
                              ┌───────────┴───────────┐
                              │  Base de datos MySQL  │
                              └───────────────────────┘
```

- **Autenticación**: tokens Bearer (Laravel Sanctum, `personal_access_tokens`). No usa sesión de cookies; el token se guarda en `localStorage` del navegador y se envía en cada request.
- **Formato de respuesta unificado**: `{ success, message, data }` en toda la API.
- **Multi-tenancy**: aislamiento automático por empresa mediante *global scope* de Eloquent (`TenantScope` / trait `BelongsToTenant`). El rol `sistemas` cruza todas las empresas.
- **Tiempo real**: eventos `ShouldBroadcastNow` sobre canales privados por usuario (`App.Models.User.{id}`), servidos por Laravel Reverb; el frontend escucha con `laravel-echo` + `pusher-js`.
- **Tareas programadas** (cron diario):
  - `creditos-prendarios:actualizar-estados` — pasa créditos a *vencido* y luego a *en venta*.
  - `cajas:cerrar-automatico` — cierra ciclos de caja que quedaron abiertos pasado su día.

---

## 3. Stack tecnológico

### Backend (`C:\Users\min\Herd\umax`)

| Componente | Versión | Uso |
|---|---|---|
| PHP | 8.2 | Lenguaje |
| Laravel Framework | 12 | Framework principal |
| Laravel Sanctum | 4 | Autenticación por token |
| Laravel Reverb | 1 | Servidor WebSocket propio |
| spatie/laravel-permission | 6 | Roles y permisos |
| barryvdh/laravel-dompdf | 3 | Generación de PDF |
| Pest | 3 / PHPUnit 11 | Testing |
| Laravel Pint | 1 | Formateo de código |
| MySQL | — | Base de datos |

### Frontend (`C:\Users\min\dev\abrahamalanya\umax-frontend`)

| Componente | Versión | Uso |
|---|---|---|
| React | 19 | UI |
| TypeScript | 6 | Tipado estático |
| Vite | 8 | Bundler / dev server |
| Material UI (MUI) | 9 | Librería de componentes (tema monocromo B/N, claro/oscuro) |
| react-router-dom | 7 | Routing SPA con lazy-loading por módulo |
| laravel-echo + pusher-js | 2 / 8 | Cliente WebSocket |
| Oxlint | 1 | Linter |
| `@fontsource/roboto` | 5 | Tipografía |

---

## 4. Módulos funcionales (detalle de lo construido)

El backend está organizado **por módulos de dominio** (`app/Modules/*`), cada uno con sus Controllers, Form Requests, Models, Policies, Services, Events y Notifications. Núcleo compartido en `app/Nucleo`.

### 4.1 Sistema / Autenticación (`Sistemas`)
- Login / logout / “me” con token Bearer; usuario devuelve sus **permisos efectivos** para que el frontend habilite/oculte UI.
- Validación de estado de usuario (activo/inactivo).
- **Roles**: listar, ver, editar permisos por rol (sincronización).
- **Permisos**: catálogo (≈50 permisos granulares tipo `creditos_prendarios.aprobar`, `cajas.reabrir`, etc.).
- **Configuración del sistema**: nombre de la app y favicon personalizables (endpoint público para que el login los lea antes de autenticar).
- **Conceptos**: catálogo de conceptos de ingreso/egreso de caja (CRUD).
- **Notificaciones**: bandeja por usuario, marcar leída / marcar todas leídas, contador en tiempo real (campana).

### 4.2 Empresa y Agencia (`Empresa`)
- CRUD de **empresas** (RUC, razón social, domicilio legal, representante legal, logo, firma digitalizada, actividad económica, prefijo).
- CRUD de **agencias** por empresa.
- Los datos de empresa alimentan los PDF de contratos.

### 4.3 Usuarios (`Usuario`)
- CRUD de usuarios con **jerarquía organizacional** (empresa_id / agencia_id).
- **Multi-rol** por usuario.
- **Roles asignables**: cada rol solo puede crear usuarios de roles iguales o inferiores.
- Consulta de **DNI** para autocompletar datos del usuario.
- Filtros de listado (por rol, estado, agencia, búsqueda).
- 7 roles definidos: `sistemas`, `administrador_general`, `secretaria`, `administrador_agencia`, `peinadora`, `supervisor`, `asesor`.

### 4.4 Clientes (`Cliente`)
- CRUD con **subida de 5 fotos** (cliente, DNI anverso, DNI reverso, casa, negocio).
- **Consulta automática de DNI** (API ConsultasPerú/RENIEC) que autocompleta nombre, apellido y dirección.
- **Asignación de asesor** responsable (con control jerárquico: un supervisor asigna clientes a sus asesores).
- **Búsqueda** de clientes (por documento / nombre).
- Visibilidad restringida por jerarquía (un asesor ve solo sus clientes; un admin de agencia ve los de su agencia; etc.).
- Unicidad de documento por empresa.

### 4.5 Bienes en garantía (`CreditoPrendario / Bien`)
- Registro de bienes del cliente: tipo, nombre, marca, modelo, serie, observación, **valorización**, **puntaje**, foto cliente+producto, video.
- **Múltiples fotos** por bien.
- Estados del bien: `en_garantia`, `disponible_venta`, `recuperado`, etc.
- **Precio de venta** (cuando el bien pasa a la tienda).
- Edición de bienes; scope de “bienes disponibles” (no comprometidos en otro crédito activo).
- Visibilidad por jerarquía.

### 4.6 Crédito prendario (`CreditoPrendario`) — módulo central

Máquina de estados completa con lógica financiera:

| Acción | Qué hace |
|---|---|
| **Registrar** | Valida caja abierta, bienes disponibles, mismo cliente, monto ≤ suma de valorizaciones. Crea el crédito en `pendiente`, marca bienes `en_garantia`, **genera contrato + declaración jurada + ficha de fotos en PDF**, notifica a los controladores. |
| **Aprobar / Rechazar** | Cambia estado, registra aprobador y fecha, notifica al asesor. Rechazo con motivo. |
| **Subsanar** | Devuelve un crédito rechazado a la cola de revisión tras corregir observaciones. |
| **Revertir aprobación** | Deshace una aprobación accidental mientras aún no se firma. |
| **Actualizar interés** | Override de tasa para casos excepcionales (solo antes de firmar). |
| **Subir documento firmado** | Se sube el escaneo firmado de cada documento; la firma se prueba por el escaneo, no por un botón. |
| **Desembolsar** | Exige todos los documentos firmados y saldo suficiente en la caja del asesor. Genera el **cronograma de cuotas** (capital amortizado en partes iguales + interés prorrateado por período), registra el **egreso en caja**, calcula plazo real y fecha de vencimiento según número y tipo de cuota (diario/semanal/quincenal/mensual). |
| **Refrendar** | Cierra el crédito y crea un **crédito sucesor encadenado**: paga el interés prorrateado (con piso mínimo de días configurable); todo excedente sobre el interés abona a capital. Respeta el máximo de refrendos configurado. Cobra en la caja del asesor con comprobante. |
| **Adendar** | Refrendo que **sí modifica condiciones** (tasa y/o tipo de cuota); el sucesor nace en `pendiente` y vuelve a pasar por aprobación/firma/desembolso, pero **sin mover caja** (no hay dinero nuevo). |
| **Liquidar** | Cobra capital + interés prorrateado, genera **acta de devolución** en PDF; el crédito queda `liquidado_pendiente` hasta que se sube el acta firmada, y recién ahí los bienes pasan a `recuperado`. |
| **Enviar a tienda** | Un crédito vencido que superó los días de espera pasa a `en_venta`; sus bienes quedan `disponible_venta` con precio fijado por bien. |
| **Cron diario** | `activo → vencido` al pasar la fecha; `vencido → en_venta` tras los días de gracia. Notifica cada transición. |
| **Ver cronograma / documentos** | Streaming de PDF regenerado al vuelo; marcar documento como impreso. |

Cálculos financieros con **precisión decimal (`bcmath`)**: interés prorrateado por días, piso mínimo de días de interés, mora diaria configurable, amortización con ajuste de residuo en la última cuota.

### 4.7 Tesorería / Caja (`Caja`)

Modelo de dos niveles: **Bóveda** (una por agencia) → **Cajas** (una por usuario operativo).

- **Bóvedas**: apertura/cierre por ciclo, **inyección de capital** (con medio y comprobante), listado y reporte de inyecciones, eliminar inyección, reapertura.
- **Cajas**: “mi caja”, apertura (incluso con fecha anterior), cierre con **resumen y arqueo**, cierre forzado por un admin, reapertura, cierre automático por cron. Ciclos diarios.
- **Movimientos de caja**: ingresos y egresos con **concepto**, medio de pago, **fotos/comprobantes**, cliente asociado opcional. Listado filtrable.
- **Billetajes**: solicitud de traspaso de efectivo bóveda↔caja, flujo de **aprobación/rechazo** con notificación en tiempo real, soporte de billetaje como medio de egreso y con cliente/voucher.
- **Cuentas bancarias** (a nivel de bóveda): CRUD, campos Yape/Plin, **movimientos** con origen y agrupación, **conciliación bancaria** (registro de conciliaciones y su historial).
- **Bancos**: catálogo (CRUD).
- Todo lo anterior emite eventos de actualización en tiempo real (badges de bóveda y caja en la barra superior del frontend se actualizan solos).

### 4.8 Tienda virtual (`Tienda`) — pública, sin autenticación
- Listado y detalle de **bienes en venta** (rematados).
- Formulario público de **“me interesa”** que registra el interés y notifica al personal.

### 4.9 Reportes (`Reportes`)
- **Reporte de movimientos de dinero**: consolidado de ingresos/egresos con filtros, para administradores generales y de agencia.

### 4.10 Núcleo compartido (`Nucleo`)
- `TenantScope` / `BelongsToTenant` — aislamiento multi-empresa.
- `ApiResponse` — formato de respuesta unificado.
- `ConsultaDniService` — integración con API externa de DNI.
- `PdfGeneratorService` — render de vistas Blade a PDF en streaming.
- `Banco` — catálogo transversal.

---

## 5. Modelo de datos

**~40 migraciones**, aproximadamente **35 tablas** de negocio, entre ellas:

`empresas`, `agencias`, `users` (+ campos de jerarquía), `personal_access_tokens`, tablas de spatie (`roles`, `permissions`, `model_has_roles`, …), `clientes`, `bovedas`, `cajas`, `boveda_ciclos`, `caja_ciclos`, `billetajes`, `caja_movimientos`, `boveda_movimientos`, `movimiento_fotos`, `bancos`, `cuentas_bancarias`, `cuenta_bancaria_movimientos`, `conciliaciones_bancarias`, `conceptos`, `configuraciones_credito_prendario`, `bienes`, `bien_fotos`, `bien_credito_prendario` (pivote), `creditos_prendarios`, `cuotas_credito_prendario`, `documentos_credito_prendario`, `intereses_bien`, `notifications`, `configuraciones_sistema`, tablas de sistema (`cache`, `jobs`).

Incluye migración de corrección de zona horaria a `America/Lima`.

Cada tabla de negocio cuenta con **factory y seeder**. Los seeders se dividen en:
- **Catálogo real / producción** (idempotentes): roles, permisos, bancos, empresa, agencias, conceptos, configuración de crédito prendario.
- **Datos de demostración** (solo `APP_ENV=local`): usuarios, bóvedas, clientes, bienes, créditos y cuentas bancarias ficticios.

---

## 6. Seguridad y control de acceso

- **Autenticación** por token Sanctum, con expiración de sesión gestionada en frontend y rehidratación vía `/auth/me`.
- **Autorización** con Policies de Laravel por cada recurso (`CreditoPrendarioPolicy`, `CajaPolicy`, `BovedaPolicy`, `ClientePolicy`, `UserPolicy`, `BilletajePolicy`, etc.) + permisos spatie.
- **Multi-tenancy** forzado a nivel de query (global scope); imposible leer datos de otra empresa aunque se manipule el ID.
- **Jerarquía de visibilidad**: servicios `*HierarchyService` que restringen qué registros ve/gestiona cada rol (asesor → propios, admin agencia → su agencia, admin general → su empresa, sistemas → todo).
- **Manejo centralizado de errores de dominio** (`DomainException → HTTP 422` con JSON).

---

## 7. Tiempo real (WebSockets)

Servidor **Laravel Reverb** propio. Eventos que se transmiten en vivo a los usuarios involucrados:

- `CreditoPrendarioActualizado` — cambios de estado del crédito.
- `BilletajeActualizado`, `CajaActualizada`, `BovedaActualizada`, `BilletajeActualizado` (billetaje), `BovedaCiclo` — tesorería.
- `NotificacionCreada` — bandeja de notificaciones + campana.

El frontend mantiene los listados abiertos sincronizados sin recargar y actualiza badges de saldo de caja/bóveda en la cabecera.

---

## 8. Generación de documentos (PDF)

Servicio `DocumentoCreditoPrendarioService` + `PdfGeneratorService` (dompdf). Documentos generados a partir de plantillas Blade con datos de empresa (logo, firma, RUC):

- **Contrato de crédito prendario**
- **Declaración jurada**
- **Ficha / anexo de fotos de bienes**
- **Acta de devolución de bienes**
- **Cronograma de cuotas**

Los documentos se regeneran al vuelo (streaming), se marcan como impresos y se sube su versión firmada escaneada.

---

## 9. Integraciones externas

- **API de consulta de DNI** (ConsultasPerú / RENIEC) — autocompletado de datos de clientes y usuarios a partir del número de documento.

---

## 10. Calidad y pruebas

- **Suite de tests con Pest**: ~67 archivos de test, **~396 casos de prueba** (feature tests), cubriendo:
  - Flujos completos de crédito (aprobación, desembolso, refrendo, adenda, liquidación, devolución, vencimiento, envío a tienda, múltiples bienes, notificaciones, tiempo real).
  - Tesorería (apertura/cierre de caja y bóveda, billetajes, inyecciones, conciliación bancaria, cierre forzado, cierre automático, reapertura, visibilidad).
  - Clientes (creación, asignación, visibilidad, subida de archivos, consulta DNI, búsqueda, policies).
  - Usuarios y roles (jerarquía de creación, roles asignables, sincronización de permisos, filtros, policies).
  - **Aislamiento multi-tenant**.
  - Tienda pública, reportes, conceptos, configuración del sistema.
- **Laravel Pint** para estilo de código consistente.
- Convención estricta documentada en `CLAUDE.md`: Form Requests para toda validación, tipado explícito, relaciones Eloquent tipadas, sin `env()` fuera de config, etc.

---

## 11. Infraestructura y despliegue

- **Backend**: servidor cPanel, PHP 8.2 (`ea-php82`), Composer, `php artisan migrate --seed`. Reverb como proceso WebSocket. Dominio previsto `erpbk.credimasperu.com`.
- **Frontend**: build estático (`npm run build` → `dist/`), servido por **nginx como SPA** en DigitalOcean (`/var/www/credimas/erpdh`), dominio `erpdh.credimasperu.com`.
- Variables de entorno separadas dev/producción; CORS configurado.
- Documentación operativa en `COMANDOS.md` (alta de usuario `sistemas`, seeders seguros para producción, arranque de Reverb, etc.).

---

## 12. Métricas del proyecto (para dimensionar esfuerzo)

### Backend

| Métrica | Valor aprox. |
|---|---|
| Líneas de código en `app/` | **11.400** |
| Líneas de tests | **7.900** |
| Líneas de migraciones | **1.850** |
| Líneas de seeders | **930** |
| Módulos de dominio | 8 (+ Núcleo) |
| Controllers | 24 |
| Form Requests | 45 |
| Models | 40 |
| Services (lógica de negocio) | 17 (el mayor, `CreditoPrendarioService`, ~870 líneas) |
| Policies | 15 |
| Events de broadcasting | 8 |
| Notifications | ~20 |
| Endpoints API | **72 rutas** |
| Migraciones | ~40 |
| Factories | 24 |
| Seeders | 14 |
| Comandos programados | 2 |
| Casos de prueba (Pest) | **~396** |
| Commits | 26 |

### Frontend

| Métrica | Valor aprox. |
|---|---|
| Líneas de código (`src/`, TS/TSX) | **13.750** |
| Páginas / vistas | **22** |
| Componentes compartidos | 17 |
| Módulos de cliente API | 21 |
| Utilidades (jerarquía, formato, roles) | 8 |
| Commits | 17 |

### Total aproximado

- **~25.000 líneas de código de aplicación** (sin contar dependencias ni tests) + **~7.900 líneas de tests**.
- **2 repositorios** desplegados de forma independiente.

---

## 13. Cronología del desarrollo

Desarrollo entre **13 y 29 de agosto de 2026** (~2,5 semanas de commits activos), en fases:

| Fecha | Hito |
|---|---|
| 13 ago | Setup Laravel + Pest + Boost; usuarios, roles y permisos base |
| 14 ago | Empresas y agencias; roles/permisos/usuarios completos |
| 18 ago | Reorganización a arquitectura modular “por pisos”; caja y créditos prendarios (base) |
| 19 ago | Bienes por cliente; bóvedas y ciclos de bóveda/caja; **WebSockets**; solicitud de crédito prendario |
| 20 ago | Créditos prendarios + tienda virtual |
| 25 ago | Crédito prendario completo (cronograma, refrendo, liquidación); seeders bóveda/crédito; **reporte de movimientos de dinero**; integración frontend caja/empresa/créditos |
| 26 ago | Ajustes de crédito prendario (adenda, interés); seeders separados local/producción; fix Reverb |
| 28 ago | **Multi-rol** por usuario; ingresos y egresos de caja con conceptos; modales |
| 29 ago | **Venta de bienes** rematados; edición de bienes; **búsqueda de clientes** |

---

## 14. Inventario de entregables (checklist para cotización)

**Backend – API REST (Laravel 12)**
- [ ] Autenticación por token + gestión de sesión
- [ ] Módulo Empresas y Agencias (CRUD)
- [ ] Módulo Usuarios con jerarquía y multi-rol
- [ ] Sistema de Roles y Permisos granulares (≈50 permisos, 7 roles) + editor de permisos por rol
- [ ] Configuración del sistema (marca blanca: nombre + favicon)
- [ ] Módulo Clientes con 5 fotos + consulta DNI + asignación + búsqueda + visibilidad jerárquica
- [ ] Módulo Bienes en garantía con fotos, video, valorización, puntaje, estados
- [ ] Módulo Crédito Prendario: máquina de estados de 12+ transiciones con lógica financiera decimal
- [ ] Cronograma de cuotas (4 modalidades: diario/semanal/quincenal/mensual)
- [ ] Refrendo, adenda, liquidación, devolución, mora, envío a tienda
- [ ] Configuración de crédito por empresa/agencia (interés, plazos, mora, máx. refrendos, días mínimos de interés)
- [ ] Tesorería: Bóvedas + inyecciones + ciclos + reapertura
- [ ] Tesorería: Cajas por usuario + ciclos diarios + apertura/cierre/arqueo + cierre forzado + cierre automático
- [ ] Billetajes (traspaso bóveda↔caja) con flujo de aprobación
- [ ] Movimientos de caja (ingresos/egresos) con conceptos, medios de pago y comprobantes
- [ ] Cuentas bancarias + movimientos + conciliación bancaria + catálogo de bancos + Yape/Plin
- [ ] Tienda virtual pública (listado, detalle, registro de interés)
- [ ] Reporte de movimientos de dinero
- [ ] Notificaciones por usuario (bandeja + campana)
- [ ] Servidor WebSocket (Laravel Reverb) + 8 eventos de tiempo real
- [ ] Generación de 5 tipos de documentos PDF con branding de empresa
- [ ] Integración API externa de consulta de DNI (RENIEC)
- [ ] Multi-tenancy (aislamiento automático por empresa)
- [ ] 2 comandos programados (cron)
- [ ] Suite de ~396 pruebas automatizadas
- [ ] Seeders de producción idempotentes + datos demo

**Frontend – SPA (React 19 + MUI)**
- [ ] Diseño responsive (web + móvil), tema claro/oscuro, marca blanca
- [ ] Login + rutas protegidas + gating de UI por permiso
- [ ] 22 páginas de módulo con lazy-loading
- [ ] Tabla de datos reutilizable con paginación, diálogos de creación/edición, confirmación
- [ ] Componentes: autocomplete de clientes, campos de bien/cliente, subida de media + lightbox, campo de medio de cobro, badges de caja/bóveda en vivo, campana de notificaciones
- [ ] Integración WebSocket (laravel-echo) para actualización en vivo
- [ ] 21 módulos de cliente API tipados
- [ ] Tienda virtual pública (2 vistas)

**DevOps / entrega**
- [ ] Despliegue backend (cPanel/PHP 8.2 + Reverb)
- [ ] Despliegue frontend (nginx SPA en DigitalOcean)
- [ ] Configuración de dominios, CORS, entornos dev/producción
- [ ] Documentación operativa (`COMANDOS.md`, `CLAUDE.md`)

---

## 15. Notas para la propuesta de precio

- El **crédito prendario** es el núcleo de mayor complejidad: reglas financieras con precisión decimal, máquina de estados, encadenamiento de créditos (refrendo/adenda), generación documental y su interacción con tesorería. Concentra el mayor esfuerzo de análisis y pruebas.
- La **tesorería** (bóveda/caja/billetajes/bancos/conciliación) es un segundo subsistema de peso comparable a un módulo contable-financiero.
- El sistema ya nace **multi-empresa y multi-agencia**, con control de acceso jerárquico y por permisos — esto multiplica el costo de QA respecto a un sistema mono-tenant.
- Hay **cobertura de pruebas real y extensa** (~396 casos): es un activo de calidad que suele cotizarse aparte cuando el cliente lo pide, aquí ya está incluido.
- Trabajo entregado en ~2,5 semanas de commits, pero sobre un alcance que en estimación tradicional equivale a varios meses-hombre de un equipo pequeño.
```