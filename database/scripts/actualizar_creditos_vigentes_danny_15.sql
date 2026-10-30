-- MySQL 8. Danny Frank Vasquez Bocanegra: usuario 8, empresa 1.
-- Solo vigentes. Conserva fechas, capital, pagos, mora pagada y estado.
-- No modifica creditos liquidados ni rechazados.
DROP TEMPORARY TABLE IF EXISTS tmp_danny_recalculo;
DROP TEMPORARY TABLE IF EXISTS tmp_danny_creditos;

START TRANSACTION;

SELECT id, codigo, estado, interes
FROM creditos_prendarios
WHERE registrado_por = 8 AND empresa_id = 1
  AND tipo_credito = 'diario' AND tipo_interes = 'simple'
  AND estado IN ('pendiente', 'aprobado', 'activo', 'vencido')
FOR UPDATE;

CREATE TEMPORARY TABLE tmp_danny_creditos AS
SELECT id
FROM creditos_prendarios
WHERE registrado_por = 8 AND empresa_id = 1
  AND tipo_credito = 'diario' AND tipo_interes = 'simple'
  AND estado IN ('pendiente', 'aprobado', 'activo', 'vencido');

SELECT q.id
FROM cuotas_credito_prendario q
JOIN tmp_danny_creditos t ON t.id = q.credito_id
JOIN creditos_prendarios c ON c.id = q.credito_id
WHERE c.estado IN ('activo', 'vencido')
FOR UPDATE;

CREATE TEMPORARY TABLE tmp_danny_recalculo AS
WITH cuotas AS (
    SELECT q.id, q.credito_id, q.monto_capital,
           TRUNCATE(
               CAST(c.monto_prestamo * 15 AS DECIMAL(40,20)) /
               CASE c.tipo_cuota
                   WHEN 'diario' THEN 3000
                   WHEN 'semanal' THEN 400
                   WHEN 'quincenal' THEN 200
                   WHEN 'mensual' THEN 100
               END, 10
           ) AS interes_nominal,
           ROW_NUMBER() OVER (PARTITION BY q.credito_id ORDER BY q.numero_cuota, q.id) AS posicion,
           COUNT(*) OVER (PARTITION BY q.credito_id) AS cantidad,
           SUM(q.monto_capital) OVER (PARTITION BY q.credito_id) AS capital_total
    FROM cuotas_credito_prendario q
    JOIN creditos_prendarios c ON c.id = q.credito_id
    WHERE c.registrado_por = 8 AND c.empresa_id = 1
      AND c.tipo_credito = 'diario' AND c.tipo_interes = 'simple'
      AND c.estado IN ('activo', 'vencido')
      AND c.id IN (SELECT id FROM tmp_danny_creditos)
), redondeadas AS (
    SELECT *, TRUNCATE(TRUNCATE(monto_capital + interes_nominal, 2) + 0.05, 1) AS total_redondeado
    FROM cuotas
), calculadas AS (
    SELECT *,
           CASE WHEN posicion = cantidad THEN
               capital_total + TRUNCATE(interes_nominal * cantidad + 0.005, 2)
               - SUM(CASE WHEN posicion < cantidad THEN total_redondeado ELSE 0 END)
                 OVER (PARTITION BY credito_id)
           ELSE total_redondeado END AS nuevo_total
    FROM redondeadas
)
SELECT id, credito_id, CAST(nuevo_total AS DECIMAL(18,2)) AS nuevo_total,
       CAST(nuevo_total - monto_capital AS DECIMAL(18,2)) AS nuevo_interes
FROM calculadas

;

-- Incluye cuotas pagadas y pendientes, igual que actualizarInteres().
UPDATE cuotas_credito_prendario q
JOIN tmp_danny_recalculo r ON r.id = q.id
SET q.monto_interes = r.nuevo_interes,
    q.monto_total = r.nuevo_total,
    q.updated_at = NOW();

UPDATE creditos_prendarios c
JOIN tmp_danny_creditos t ON t.id = c.id
SET c.interes = 15.00,
    c.updated_at = NOW();

SELECT
    (SELECT COUNT(*) FROM tmp_danny_creditos) AS creditos_vigentes,
    (SELECT COUNT(*) FROM tmp_danny_recalculo) AS cuotas_recalculadas;

COMMIT;

DROP TEMPORARY TABLE tmp_danny_recalculo;
DROP TEMPORARY TABLE tmp_danny_creditos;