# Historial de créditos y desembolsos (web)

Replica en la web de los dos módulos que ya existían solo en la app: `HistorialCreditosScreen.tsx` y `HistorialDesembolsosScreen.tsx` (`umax-app/src/screens/creditos/`). El backend no necesitó ningún cambio: `GET /creditos-prendarios` (`CreditoController::index()`) ya acepta todos los filtros que usan ambas pantallas (código, tipo_credito, cliente_id, estado[], agencia_id, asesor_id, y los tres rangos de fecha — desembolso, vencimiento, cancelación), con el mismo permiso `creditos_prendarios.ver` que ya exige `CreditoPolicy::viewAny`.

**Código ya escrito, probado en el navegador contra datos reales de producción local, y pendiente de commit/deploy.** No se tocó la base de datos — no hace falta migración, solo subir los archivos.

## Lo implementado

Dos páginas nuevas en `umax-frontend`:

- `src/pages/HistorialCreditosPage.tsx` → ruta `/creditos-prendarios/historial`. Filtros: cliente, asesor (solo roles admin, como en CreditosPrendariosPage), tipo de crédito, estado (default "Todos (desembolsados)" = `ESTADOS_DESEMBOLSADOS`), agencia (solo sistemas/administrador_general/supervisor), y 3 rangos de fecha. Columnas: cliente, DNI, código, tipo, tipo de pago, estado, las 3 fechas, préstamo/abonos/saldo, días de atraso (con el mismo semáforo de colores y leyenda togglable que la app — `colorHistorialCredito`, portado a `utils/creditoPrendarioHierarchy.ts`), asesor. Acciones por fila: "Ver detalle" y "Ver pagos".
- `src/pages/HistorialDesembolsosPage.tsx` → ruta `/creditos-prendarios/historial-desembolsos`. Subconjunto sin filtro de estado (fijo a `ESTADOS_DESEMBOLSADOS`) ni rangos de vencimiento/cancelación; columnas centradas en el desembolso (cuotas, interés). Solo "Ver detalle".

Reutiliza en vez de duplicar: `DataTable`, `FiltrosPanel`, `ClienteAutocomplete`, `AsesorAutocomplete`. Extendí `listCreditos()` (`api/creditosPrendarios.ts`) con los filtros que le faltaban (`agenciaId`, `fechaVencimientoDesde/Hasta`, `fechaCancelacionDesde/Hasta`) — ya soportados por el backend, nunca expuestos en el wrapper. "Ver detalle" no abre un diálogo propio: navega a `/creditos-prendarios/{tipo}?credito_id=&accion=detalle`, el mismo mecanismo que ya usa "Mi ruta de cobranza" para reabrir el diálogo de detalle existente (cronograma, documentos, evidencia de desembolso). "Ver pagos" navega a `/cobros?cliente_id=`. Menú nuevo en `AppLayout.tsx` (grupo "Créditos"), gateado por `permiso: 'creditos_prendarios.ver'`.

## Bug preexistente encontrado y corregido de paso

Probando "Ver pagos" until el navegador: el deep-link `?cliente_id=` de Cobranzas (ya usado por "Mi ruta de cobranza", no algo nuevo) tenía una condición de carrera — `loadCobros()` no descartaba respuestas desactualizadas, así que si la petición SIN filtro (la del montaje inicial) resolvía después que la filtrada, pisaba el resultado y mostraba pagos de otros clientes con el chip de filtro puesto. Corregido en `CobranzasPage.tsx` con el mismo guard de `requestId` que ya usa `CreditosPrendariosPage.tsx`. Confirmado en vivo: antes de 424 páginas sin filtrar realmente, después 2 páginas, solo ese cliente.

## Archivos modificados (umax-frontend, nada en el backend)

- `src/pages/HistorialCreditosPage.tsx` (nuevo)
- `src/pages/HistorialDesembolsosPage.tsx` (nuevo)
- `src/api/creditosPrendarios.ts` (filtros nuevos en `ListCreditosFilters`/`listCreditos`)
- `src/utils/creditoPrendarioHierarchy.ts` (`ESTADOS_DESEMBOLSADOS`, `HISTORIAL_ATRASO_LEYENDA`, `colorHistorialCredito`)
- `src/components/AppLayout.tsx` (dos entradas de menú)
- `src/App.tsx` (dos rutas nuevas)
- `src/pages/CobranzasPage.tsx` (fix de la condición de carrera)

## Para mañana

1. Revisar el diff completo de `umax-frontend` (`git status`/`git diff`) — nada está commiteado todavía.
2. Confirmar el commit (y si aplica, el de los otros pendientes de esta sesión: APK, saldo/liquidación de créditos diarios, evidencia de desembolso en el detalle).
3. Build + deploy del frontend web — no requiere nada en el servidor backend (sin migración).
4. Si se quiere, probar también desde un rol no-admin (asesor/supervisor) para confirmar que los filtros de agencia/asesor quedan ocultos como corresponde — solo se probó con un usuario con permisos amplios.
