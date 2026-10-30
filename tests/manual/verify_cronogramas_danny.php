<?php

// Run with Herd PHP from the repository root. Uses only TEMPORARY fixtures.
// Permanent tables are shadowed on this connection and never updated.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('Esta prueba requiere MySQL.');
}

function checkSql(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$pdo->exec('CREATE TEMPORARY TABLE users (id BIGINT PRIMARY KEY, nombre VARCHAR(100), apellido VARCHAR(100), empresa_id BIGINT, agencia_id BIGINT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO users VALUES (8, 'danny frank', 'vasquez bocanegra', 1, 3)");
$pdo->exec('CREATE TEMPORARY TABLE creditos_prendarios (
    id BIGINT PRIMARY KEY, codigo VARCHAR(30), agencia_id BIGINT, empresa_id BIGINT, registrado_por BIGINT,
    estado VARCHAR(20), tipo_cuota VARCHAR(20), tipo_interes VARCHAR(20), plazo_dias INT, numero_cuotas INT,
    fecha_desembolso DATE, fecha_vencimiento DATE, updated_at DATETIME, monto_prestamo DECIMAL(12,2)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE cuotas_credito_prendario (
    id BIGINT PRIMARY KEY, credito_id BIGINT, empresa_id BIGINT, numero_cuota INT,
    fecha_vencimiento DATE, updated_at DATETIME, pagada_at DATETIME, monto_abonado DECIMAL(12,2),
    monto_total DECIMAL(12,2), cobro_id BIGINT
) ENGINE=InnoDB');
$insertCredit = $pdo->prepare('INSERT INTO creditos_prendarios VALUES (?, ?, 3, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 500)');
$insertCuota = $pdo->prepare('INSERT INTO cuotas_credito_prendario VALUES (?, ?, ?, ?, ?, NULL, ?, 10, 100, 99)');
// Daily, weekly with 30-day final installment, monthly compound end-of-month,
// another advisor, already vencido, wrong tenant, incomplete schedule, aligned schedule.
$cases = [
    [1, 1, 8, 'activo', 'diario', 'simple', 3, 3, '2026-10-20', '2026-11-02'],
    [2, 1, 8, 'activo', 'semanal', 'simple', 30, 4, '2026-10-20', '2026-11-29'],
    [3, 1, 8, 'activo', 'mensual', 'compuesto', 120, 4, '2026-01-31', '2026-03-02'],
    [4, 1, 9, 'activo', 'diario', 'simple', 3, 3, '2026-10-20', '2026-11-02'],
    [5, 1, 8, 'vencido', 'diario', 'simple', 3, 3, '2026-10-20', '2026-11-02'],
    [6, 2, 8, 'activo', 'diario', 'simple', 3, 3, '2026-10-20', '2026-11-02'],
    [7, 1, 8, 'activo', 'diario', 'simple', 3, 3, '2026-10-20', '2026-11-02'],
    [8, 1, 8, 'activo', 'diario', 'simple', 3, 3, '2026-10-20', '2026-10-23'],
];
foreach ($cases as [$id, $empresa, $asesor, $estado, $frecuencia, $interes, $plazo, $n, $base, $fin]) {
    $insertCredit->execute([$id, "TEST-$id", $empresa, $asesor, $estado, $frecuencia, $interes, $plazo, $n, $base, $fin]);
    for ($i = 1; $i <= ($id === 7 ? 2 : $n); $i++) {
        $fecha = $id === 8 ? '2026-10-'.(20 + $i) : '2026-10-'.(28 + $i % 3);
        $insertCuota->execute([$id * 10 + $i, $id, $empresa, $i, $fecha, $i === 1 ? '2026-10-30 10:00:00' : null]);
    }
}
$snapshot = fn () => [
    $pdo->query('SELECT * FROM creditos_prendarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    $pdo->query('SELECT * FROM cuotas_credito_prendario ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];
$before = $snapshot();
$sql = file_get_contents(__DIR__.'/../../database/scripts/corregir_cronogramas_danny.sql');
$run = function (string $script) use ($pdo): void {
    $script = preg_replace('/^\s*--.*$/m', '', $script);
    $withinTransaction = false;
    foreach (explode(';', $script) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $result = $pdo->query($statement);
        if ($result->columnCount()) {
            $result->fetchAll();
        }
        $result->closeCursor();
        if ($statement === 'START TRANSACTION') {
            $withinTransaction = true;
        } elseif ($statement === 'COMMIT') {
            $withinTransaction = false;
        }
        checkSql(! $withinTransaction || $pdo->inTransaction(), 'Una sentencia produjo un COMMIT implicito.');
    }
};
$run($sql);
checkSql($snapshot() === $before, 'El diagnostico modifico datos.');
$run(str_replace('SET @aplicar = 0;', 'SET @aplicar = 1;', $sql));
[$credits, $cuotas] = $snapshot();
$dates = fn ($creditId) => array_column(array_values(array_filter($cuotas, fn ($q) => $q['credito_id'] == $creditId)), 'fecha_vencimiento');
checkSql($dates(1) === ['2026-10-21', '2026-10-22', '2026-10-23'], 'Fechas diarias incorrectas.');
checkSql($dates(2) === ['2026-10-27', '2026-11-03', '2026-11-10', '2026-11-19'], 'No preserva la ultima cuota a 30 dias.');
checkSql($dates(3) === ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'], 'No respeta meses sin desbordamiento.');
foreach ($cuotas as $index => $cuota) {
    $original = $before[1][$index];
    if (in_array((int) $cuota['credito_id'], [1, 2, 3], true)) {
        unset($cuota['fecha_vencimiento'], $cuota['updated_at'], $original['fecha_vencimiento'], $original['updated_at']);
    }
    checkSql($cuota === $original, 'Se modificaron pagos o cuotas fuera del alcance.');
}
foreach ($credits as $index => $credit) {
    if (! in_array((int) $credit['id'], [1, 2, 3], true)) {
        checkSql($credit === $before[0][$index], 'Se modifico un credito excluido o ya alineado.');
    }
}
checkSql($credits[0]['fecha_vencimiento'] === '2026-10-23', 'Vencimiento del credito diario incorrecto.');
checkSql($credits[2]['fecha_vencimiento'] === '2026-02-28', 'Vencimiento compuesto distinto de la regla del backend.');
$applied = $snapshot();
$run(str_replace('SET @aplicar = 0;', 'SET @aplicar = 1;', $sql));
checkSql($snapshot() === $applied, 'La segunda ejecucion no es idempotente.');
$run(str_replace("SET @nombre_esperado = 'danny frank vasquez bocanegra';", "SET @nombre_esperado = 'otra persona';", str_replace('SET @aplicar = 0;', 'SET @aplicar = 1;', $sql)));
checkSql($snapshot() === $applied, 'No se protegio la identidad del asesor.');
echo "OK: diagnostico, fechas, meses, plazo final, pagos, alcance, identidad e idempotencia. Solo tablas temporales.\n";
