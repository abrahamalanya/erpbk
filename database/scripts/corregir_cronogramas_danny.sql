-- MySQL 8.x. Ejecutar el archivo completo en UNA misma conexion, sin --force.
-- Primero ejecutar con @aplicar = 0 y exportar/revisar los resultados.
-- Para guardar, respaldar la base y volver a ejecutar con @aplicar = 1.
-- No ejecutar mientras se registran cobros o se editan estos creditos.
-- Identificadores verificados en la base LOCAL; revisar si se usa otra base.
-- Alcance: creditos activos REGISTRADOS por Danny (criterio del listado UMAX).
-- No modifica fecha_desembolso, importes, cobros, abonos ni movimientos de caja.
-- Al corregir fechas, un credito puede pasar de activo a vencido, igual que en UMAX.
-- SQL directo no emite notificaciones ni genera documentos de Laravel.

SET @aplicar = 0;
SET @asesor_id = 8;
SET @empresa_id = 1;
SET @nombre_esperado = 'danny frank vasquez bocanegra';
SET @fecha_corte = DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-05:00'));

SELECT id, nombre, apellido, empresa_id, agencia_id
FROM users WHERE LOWER(nombre) LIKE '%danny%';

-- La identidad debe coincidir ademas de los IDs; de lo contrario no hay cambios.
SET @identidad_correcta = (
    SELECT COUNT(*) = 1 FROM users
    WHERE id = @asesor_id AND empresa_id = @empresa_id
      AND CAST(LOWER(TRIM(CONCAT(nombre, ' ', apellido))) AS BINARY) = CAST(@nombre_esperado AS BINARY)
);
SELECT @aplicar AS aplicar, @identidad_correcta AS identidad_correcta,
       @fecha_corte AS fecha_corte_lima;

DROP TEMPORARY TABLE IF EXISTS umax_danny_cuotas;
DROP TEMPORARY TABLE IF EXISTS umax_danny_creditos;
DROP TEMPORARY TABLE IF EXISTS umax_danny_estructura;

START TRANSACTION;

-- Bloquear antes de calcular el plan para impedir cambios concurrentes de fechas/pagos.
SELECT c.id AS credito_bloqueado
FROM creditos_prendarios c
WHERE @identidad_correcta = 1 AND c.empresa_id = @empresa_id
  AND c.registrado_por = @asesor_id AND c.estado = 'activo'
FOR UPDATE;

SELECT q.id AS cuota_bloqueada
FROM cuotas_credito_prendario q
JOIN creditos_prendarios c ON c.id = q.credito_id AND c.empresa_id = q.empresa_id
WHERE @identidad_correcta = 1 AND c.empresa_id = @empresa_id
  AND c.registrado_por = @asesor_id AND c.estado = 'activo'
FOR UPDATE;

CREATE TEMPORARY TABLE umax_danny_estructura AS
SELECT c.id AS credito_id, COUNT(q.id) AS cantidad,
       MIN(q.numero_cuota) AS primera, MAX(q.numero_cuota) AS ultima,
       COUNT(DISTINCT q.numero_cuota) AS numeros_distintos
FROM creditos_prendarios c
LEFT JOIN cuotas_credito_prendario q ON q.credito_id = c.id AND q.empresa_id = c.empresa_id
WHERE @identidad_correcta = 1 AND c.empresa_id = @empresa_id
  AND c.registrado_por = @asesor_id AND c.estado = 'activo'
GROUP BY c.id;

-- Excluir cronogramas ausentes/incompletos o condiciones no reconocidas.
-- La ultima cuota simple respeta plazo_dias (por ejemplo, semanal a 30 dias).
CREATE TEMPORARY TABLE umax_danny_creditos AS
SELECT c.id AS credito_id, c.codigo, c.agencia_id, c.fecha_desembolso,
       c.tipo_cuota, c.tipo_interes, c.plazo_dias, e.ultima,
       CAST(0 AS UNSIGNED) AS cuotas_a_corregir,
       c.fecha_vencimiento AS vencimiento_anterior, c.estado AS estado_anterior,
       CASE
         WHEN c.tipo_interes = 'compuesto' AND c.tipo_cuota = 'mensual'
           THEN DATE_ADD(DATE(c.fecha_desembolso), INTERVAL 1 MONTH)
         WHEN c.tipo_interes = 'compuesto'
           THEN DATE_ADD(DATE(c.fecha_desembolso), INTERVAL
             (CASE c.tipo_cuota WHEN 'diario' THEN 1 WHEN 'semanal' THEN 7
                   WHEN 'quincenal' THEN 15 WHEN 'mensual' THEN 30 END) DAY)
         ELSE DATE_ADD(DATE(c.fecha_desembolso), INTERVAL c.plazo_dias DAY)
       END AS vencimiento_nuevo
FROM creditos_prendarios c
JOIN umax_danny_estructura e ON e.credito_id = c.id
WHERE c.empresa_id = @empresa_id AND c.registrado_por = @asesor_id AND c.estado = 'activo'
  AND c.fecha_desembolso IS NOT NULL AND DATE(c.fecha_desembolso) <= @fecha_corte
  AND c.tipo_interes IN ('simple', 'compuesto')
  AND c.tipo_cuota IN ('diario', 'semanal', 'quincenal', 'mensual')
  AND c.plazo_dias > 0
  AND e.cantidad > 0 AND e.primera = 1 AND e.ultima = e.cantidad
  AND e.numeros_distintos = e.cantidad
  AND (c.numero_cuotas IS NULL OR c.numero_cuotas = e.cantidad)
  AND (c.tipo_interes = 'compuesto' OR c.plazo_dias >
       (e.ultima - 1) * CASE c.tipo_cuota WHEN 'diario' THEN 1 WHEN 'semanal' THEN 7
                                        WHEN 'quincenal' THEN 15 WHEN 'mensual' THEN 30 END);

CREATE TEMPORARY TABLE umax_danny_cuotas AS
SELECT q.id AS cuota_id, q.credito_id, q.numero_cuota,
       q.fecha_vencimiento AS fecha_anterior, q.pagada_at, q.monto_abonado,
       CASE
         WHEN c.tipo_interes = 'compuesto' AND c.tipo_cuota = 'mensual'
           THEN DATE_ADD(DATE(c.fecha_desembolso), INTERVAL q.numero_cuota MONTH)
         ELSE DATE_ADD(DATE(c.fecha_desembolso), INTERVAL
           (CASE WHEN c.tipo_interes = 'simple' AND q.numero_cuota = c.ultima THEN c.plazo_dias
                 ELSE q.numero_cuota * CASE c.tipo_cuota WHEN 'diario' THEN 1 WHEN 'semanal' THEN 7
                        WHEN 'quincenal' THEN 15 WHEN 'mensual' THEN 30 END END) DAY)
       END AS fecha_nueva
FROM cuotas_credito_prendario q
JOIN umax_danny_creditos c ON c.credito_id = q.credito_id
WHERE q.empresa_id = @empresa_id;

UPDATE umax_danny_creditos c
JOIN (SELECT credito_id, SUM(NOT (fecha_anterior <=> fecha_nueva)) AS cantidad
      FROM umax_danny_cuotas GROUP BY credito_id) q ON q.credito_id = c.credito_id
SET c.cuotas_a_corregir = q.cantidad;

-- DIAGNOSTICO: creditos excluidos, que requieren revision individual.
SELECT c.id AS credito_excluido, c.codigo, c.fecha_desembolso,
       c.tipo_cuota, c.tipo_interes, c.plazo_dias, c.numero_cuotas,
       e.cantidad AS cuotas_guardadas, e.primera, e.ultima
FROM creditos_prendarios c
JOIN umax_danny_estructura e ON e.credito_id = c.id
LEFT JOIN umax_danny_creditos p ON p.credito_id = c.id
WHERE p.credito_id IS NULL;

-- DIAGNOSTICO: resumen y detalle de las fechas que cambiarian.
SELECT credito_id, codigo, agencia_id, fecha_desembolso, tipo_cuota, tipo_interes,
       vencimiento_anterior, vencimiento_nuevo, cuotas_a_corregir, estado_anterior,
       IF(vencimiento_nuevo < @fecha_corte, 'vencido', 'activo') AS estado_propuesto
FROM umax_danny_creditos
WHERE cuotas_a_corregir > 0 OR NOT (vencimiento_anterior <=> vencimiento_nuevo)
ORDER BY credito_id;

SELECT c.codigo, q.cuota_id, q.numero_cuota, q.fecha_anterior, q.fecha_nueva,
       q.pagada_at, q.monto_abonado
FROM umax_danny_cuotas q JOIN umax_danny_creditos c ON c.credito_id = q.credito_id
WHERE NOT (q.fecha_anterior <=> q.fecha_nueva)
ORDER BY q.credito_id, q.numero_cuota;

-- Solo estas columnas se modifican, y solo si se habilito expresamente @aplicar.
UPDATE cuotas_credito_prendario q
JOIN umax_danny_cuotas p ON p.cuota_id = q.id AND p.credito_id = q.credito_id
SET q.fecha_vencimiento = p.fecha_nueva, q.updated_at = CURRENT_TIMESTAMP
WHERE @aplicar = 1 AND q.empresa_id = @empresa_id
  AND NOT (p.fecha_anterior <=> p.fecha_nueva);
SET @cuotas_actualizadas = ROW_COUNT();

UPDATE creditos_prendarios c
JOIN umax_danny_creditos p ON p.credito_id = c.id
SET c.fecha_vencimiento = p.vencimiento_nuevo,
    c.estado = IF(p.vencimiento_nuevo < @fecha_corte, 'vencido', 'activo'),
    c.updated_at = CURRENT_TIMESTAMP
WHERE @aplicar = 1 AND c.empresa_id = @empresa_id
  AND c.registrado_por = @asesor_id AND c.estado = 'activo'
  AND (p.cuotas_a_corregir > 0 OR NOT (p.vencimiento_anterior <=> p.vencimiento_nuevo));
SET @creditos_actualizados = ROW_COUNT();

SELECT @aplicar AS aplicar, @cuotas_actualizadas AS cuotas_actualizadas,
       @creditos_actualizados AS creditos_actualizados;
COMMIT;

DROP TEMPORARY TABLE umax_danny_cuotas;
DROP TEMPORARY TABLE umax_danny_creditos;
DROP TEMPORARY TABLE umax_danny_estructura;
