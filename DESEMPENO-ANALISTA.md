# Módulo: Desempeño de Cobradores / Analistas

Estado: **planeación** — no implementado. Este documento resume el análisis hecho sobre la tabla de ejemplo que compartieron, qué datos ya tenemos para calcularla y qué falta antes de poder construirla.

## 1. Origen

Nos compartieron una tabla (Excel/Sheets) que usan hoy para medir el desempeño mensual de cada cobrador/analista. Objetivo: replicar ese reporte dentro de umax, alimentado con datos reales del sistema en vez de armarse a mano.

## 2. Tabla objetivo (columnas)

| # | Columna | Significado |
|---|---|---|
| 1 | Cobrador | Asesor |
| 2 | Zona | Agencia |
| 3 | Meta Cobranza (S/) | Objetivo mensual de cobranza |
| 4 | Cobranza Recuperada | Cobrado real en el mes |
| 5 | Eficiencia de recuperación (%) | (4) / (3) × 100 |
| 6 | Meta Desembolso | Objetivo mensual de desembolso |
| 7 | Desembolso del mes | Desembolsado real en el mes |
| 8 | Eficiencia de Desembolsos (%) | (7) / (6) × 100 |
| 9 | Total de clientes | Cartera asignada al asesor |
| 10 | Clientes Gestionados | Clientes "trabajados" en el mes (definición sin confirmar, ver §5) |
| 11 | Clientes verdes | Clientes sin cuota vencida |
| 12 | Clientes en mora | Clientes con cuota vencida sin pagar |
| 13 | Eficiencia de gestión (%) | (10) / (9) × 100 |
| 14 | Clientes Verdes Meta (%) | Umbral objetivo (90% en todas las filas → parece fijo, no por asesor) |
| 15 | Clientes Verdes (%) | (11) / (9) × 100 |
| 16 | Calificación | Texto derivado de (15): ≥~90% EXCELENTE, si no ACEPTABLE |
| 17 | Clientes en Mora (%) | (12) / (9) × 100 |
| 18 | Mora meta (%) | Umbral objetivo (70% en todas las filas → también parece fijo) |
| 19 | Monto total Mora | Mora acumulada de la cartera del asesor |
| 20 | Mora recuperada | Mora efectivamente cobrada en el mes |
| 21 | Recuperación de Mora (%) | (20) / (19) × 100 |
| 22 | Horas del mes trabajadas | Horas trabajadas (idéntico "1248" en las 6 filas — sospechoso, ver §5) |
| 23 | Eficiencia horaria | Fórmula no identificada |
| 24 | Eficiencia Operativa global (%) | Fórmula no identificada |
| 25–29 | *(columnas de ponderación)* | Ver fórmula de Puntaje Total en §3 |
| 30 | Puntaje Total | Suma ponderada, ver §3 |
| 31 | Clasificación | ≥90 EXCELENTE, si no BUENO (con los 6 casos vistos no se ve el corte de ACEPTABLE/DEFICIENTE) |

## 3. Fórmula reconstruida (verificada exacta contra las 6 filas de ejemplo)

```
Puntaje Total = 0.30 × Eficiencia de recuperación (%)
              + 0.40 × Eficiencia de Desembolsos (%)
              + 0.20 × Clientes Verdes (%)
              + 0.05 × Recuperación de Mora (%)
              + 0.05 × Eficiencia de gestión (%)
```

**Ojo — error de rotulado detectado en la plantilla original:** la columna de ponderación que en el encabezado dice "Clientes en Mora (%)" (los valores chicos: 0.1, 0.7, 1.8…) en realidad sale de **Recuperación de Mora (%) × 5%**, no de Clientes en Mora (%). Confirmar esto con quien mantiene la plantilla antes de implementar, para no replicar el error.

Clasificación final: `Puntaje Total >= 90` → EXCELENTE, si no → BUENO. No hay evidencia en la muestra de más niveles (ACEPTABLE/DEFICIENTE podrían existir por debajo de cierto puntaje, pero no aparecen en los 6 casos vistos).

## 4. Qué ya tenemos en umax (reusable)

| Dato | Fuente en el código |
|---|---|
| Cobranza recuperada por asesor | `ReporteCobranzaMensualService::cobranzaMensual()/cobranzaAnual()` → `cobranzaPorAsesor` |
| Desembolso del mes por asesor | `ReporteCobranzaMensualService` → `desembolsosPorAsesor` |
| Total de clientes por asesor | `Cliente::where('asesor_id', ...)` |
| Clientes en mora / verdes | Misma lógica que `RutaCobranzaService::clientesEnMoraDe()` (clientes con cuota vencida sin pagar) |
| Monto total mora (cartera) | `CreditoService::calcularMora(Credito $credito)`, sumado por los créditos vencidos del asesor |
| Mora recuperada en el mes | Campo `Cobro.mora`, filtrado por `registrado_por` y rango de fechas |
| Recuperación de Mora (%) | Calculable a partir de los dos anteriores |
| Umbrales fijos (90% verdes, 70% mora) | No existen hoy, pero son constantes de empresa — mismo patrón que `ConfiguracionCredito` (una fila de configuración, no por asesor) |

Es decir: cobranza, desembolso, clientes en mora/verdes y mora ya son 100% calculables con datos que el sistema ya guarda — varias de estas columnas son prácticamente el mismo dato que ya expone el reporte de Cobranza y Desembolso Mensual.

## 5. Qué falta — bloqueante antes de implementar

1. **Metas de Cobranza y Desembolso por asesor/mes.** No existe ningún campo de "meta" en toda la base de datos. Sin esto no se puede calcular Eficiencia de recuperación ni Eficiencia de Desembolsos (que además pesan 30% y 40% del puntaje — son las columnas más importantes). Hace falta una pantalla nueva de configuración de metas (por asesor, por mes, o por agencia con reparto).
2. **Definición de "Clientes Gestionados".** No hay ningún registro de "gestión"/visita/contacto en el sistema hoy. Hipótesis con los números de la muestra: clientes distintos con al menos un cobro registrado ese mes — hay que confirmarla con quien arma el reporte actual. Si la definición real es otra (visitas, llamadas, intentos de contacto), se necesita un módulo de seguimiento nuevo que hoy no existe.
3. **Horas del mes trabajadas.** No hay control de asistencia/horario. `CajaCiclo.abierta_at`/`cerrada_at` (apertura/cierre de caja del asesor) es la aproximación más cercana disponible, pero no es un registro real de horas trabajadas. El valor "1248" idéntico en las 6 filas de la muestra sugiere que tampoco lo están calculando de verdad del otro lado — confirmar antes de construir nada sobre esto.
4. **Fórmula de Eficiencia horaria y Eficiencia Operativa global (%).** No se pudo reconstruir con los datos de la muestra (no cuadra con ninguna combinación simple de las demás columnas). Depende además del punto 3. Preguntar directamente la fórmula.

## 6. Preguntas para resolver con el usuario/negocio antes de codear

- ¿Cómo y quién define la Meta de Cobranza / Desembolso de cada asesor cada mes? ¿Es manual (un admin la ingresa) o se calcula de algo (ej. % de la cartera)?
- ¿Qué cuenta exactamente como "cliente gestionado"?
- ¿Se va a llevar horas trabajadas en serio, o esa columna se deja fuera de la v1?
- Fórmula real de Eficiencia horaria y Eficiencia Operativa global.
- ¿Los umbrales 90% (verdes) y 70% (mora) son fijos de empresa, o varían por agencia/tipo de crédito?
- ¿Existen más niveles de Clasificación además de BUENO/EXCELENTE (ej. ACEPTABLE, DEFICIENTE) y sus cortes exactos?

## 7. Propuesta de diseño técnico (borrador, para cuando se resuelvan las preguntas de §6)

Reutilizar el patrón ya establecido en `app/Modules/Reportes` en vez de crear un módulo nuevo desde cero:

- **Nueva tabla `metas_comerciales`** (o similar): `empresa_id`, `agencia_id` (nullable, override), `asesor_id` (nullable, override — mismo patrón de resolución que `ConfiguracionCredito`/`ConfiguracionCreditoService::resolverPara()`), `mes` (o `anio`+`mes`), `meta_cobranza`, `meta_desembolso`.
- **Nueva tabla o config global** para los umbrales (`clientes_verdes_meta_pct`, `mora_meta_pct`) — si terminan siendo fijos de empresa, puede ser una fila de configuración simple en vez de una tabla por asesor.
- **`RendimientoAnalistaService`** (nuevo, en `app/Modules/Reportes/Services` o un módulo propio): un método `rendimientoMensual(User $actor, string $mes, ...)` que arme la fila completa por asesor, reutilizando:
  - `ReporteCobranzaMensualService` para cobranza/desembolso reales,
  - la lógica de `RutaCobranzaService::clientesEnMoraDe()` para clientes en mora/verdes,
  - `CreditoService::calcularMora()` para el monto total de mora,
  - `Cobro.mora` para la mora recuperada.
- **Controller + rutas** siguiendo el patrón de `ReporteCobranzaMensualController`.
- **Frontend/app**: página nueva tipo tabla (`DataTable` en el frontend web), con el mismo esquema de filtros (empresa/agencia/asesor/mes) que ya usan los demás reportes.

## 8. Siguiente paso

Antes de escribir código: resolver las preguntas del §6 con quien mantiene la plantilla original, sobre todo cómo se van a cargar las Metas (bloqueante para el 70% del puntaje) y la definición de Clientes Gestionados.
