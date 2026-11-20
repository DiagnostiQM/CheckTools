<?php
/**
 * Sincronización servidor central (checkdqm @ sysdqm.com) -> base local del checador.
 *
 * Objetivo: NO bloquear tablas/filas locales mientras corre, para que un empleado
 * que está checando (entrada/salida) no sea rechazado ni se quede esperando.
 *
 * Estrategia:
 *  1. Se lee TODO el origen primero (lectura pura, sin bloquear nada) en una
 *     sola "foto" consistente. La latencia de red ya no alarga ningún bloqueo local.
 *  2. Se compara contra lo que ya hay en local (en PHP) y solo se escriben las filas
 *     nuevas o que realmente cambiaron. Las filas sin cambios nunca se tocan ni se bloquean.
 *  3. Las escrituras locales se aplican en transacciones cortas (lotes de TAM_LOTE),
 *     con statement_timeout corto: si algo está ocupado, el sincronizador cede y reintenta,
 *     en lugar de dejar al checador esperando detrás de él.
 *  4. Un candado "advisory" evita que dos ejecuciones simultáneas (cron encimado)
 *     compitan entre sí.
 */
include_once 'conexion.php';

const TAM_LOTE       = 200;      // operaciones por transacción local
// PostgreSQL 9.2 no tiene lock_timeout. Como cada sentencia del sincronizador es de
// UNA sola fila (tarda milisegundos), statement_timeout hace la misma función:
// si una sentencia tarda más de esto es porque está esperando una fila ocupada,
// se cancela (57014), se deshace el lote y se reintenta.
const STMT_TIMEOUT   = '3s';
const MAX_REINTENTOS = 3;
const LLAVE_CANDADO  = 7310524;  // id del advisory lock (cualquier entero fijo)

// Definición de tablas: columnas a copiar y columnas que se actualizan si ya existe la fila.
// (Se respeta exactamente lo que hacía el script original.)
$TABLAS = [
    'personales' => [
        'pk' => 'cve_personal',
        'columnas' => ['cve_personal', 'nombre', 'paterno', 'materno', 'rfc', 'cve_subarea', 'fecha_registro', 'creado_por',
                       'ip_registro', 'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro',
                       'fecha_nacimiento', 'sexo', 'numero_oficina', 'correo_electronico', 'estatus_personal',
                       'cve_puesto', 'cve_marca', 'nss', 'num_empleado', 'url_foto', 'cve_estado', 'cve_sucursal', 'clave_acceso'],
        'actualizar' => ['estatus_registro', 'nss', 'num_empleado', 'clave_acceso', 'estatus_personal'],
    ],
    'turnos' => [
        'pk' => 'cve_turno',
        'columnas' => ['cve_turno', 'desc_turno', 'tipo_turno', 'fecha_registro', 'creado_por', 'ip_registro',
                       'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro'],
        'actualizar' => ['desc_turno', 'tipo_turno', 'fecha_registro', 'creado_por', 'ip_registro',
                         'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro'],
    ],
    'dias_laborables' => [
        'pk' => 'cve_dia_laborable',
        'columnas' => ['cve_dia_laborable', 'desc_dia_laborable', 'cve_turno', 'fecha_registro', 'creado_por',
                       'ip_registro', 'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro',
                       'dia_labor', 'horario_entrada', 'horario_salida', 'tolerancia_entrada', 'inicio_descanso',
                       'fin_descanso', 'tiempo_descanso'],
        'actualizar' => ['horario_entrada', 'horario_salida', 'dia_labor'],
    ],
    'personal_turnos' => [
        'pk' => 'cve_personal_turno',
        'columnas' => ['cve_personal_turno', 'cve_turno', 'cve_personal', 'fecha_registro', 'creado_por', 'ip_registro',
                       'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro', 'estatus_turno',
                       'tiene_turno_temporal'],
        'actualizar' => ['estatus_turno'],
    ],
    'notificaciones' => [
        'pk' => 'cve_notificacion',
        'columnas' => ['cve_notificacion', 'desc_notificacion', 'mostrar_notificacion', 'fecha_registro', 'creado_por',
                       'ip_registro', 'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro'],
        'actualizar' => ['desc_notificacion', 'mostrar_notificacion', 'fecha_registro', 'creado_por',
                         'ip_registro', 'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro'],
        'borrar_sobrantes' => true,   // antes: DELETE de todo + reinsertar
    ],
    'calendario_festivos' => [
        'pk' => 'cve_calendario',
        'columnas' => ['cve_calendario', 'anio', 'nota_festivo', 'fecha_registro', 'creado_por',
                       'ip_registro', 'fecha_modificacion', 'modificado_por', 'ip_modifico',
                       'estatus_registro', 'fecha_festivo_inicio', 'fecha_festivo_fin', 'fecha_festivo_fin_real'],
        'actualizar' => [],           // solo inserta las nuevas
    ],
    'vacaciones' => [
        'pk' => 'cve_vacacion',
        'columnas' => ['cve_vacacion', 'estatus_vacaciones', 'fecha_inicio', 'fecha_final', 'cve_personal', 'fecha_registro',
                       'creado_por', 'ip_registro', 'fecha_modificacion', 'modificado_por', 'ip_modifico', 'estatus_registro'],
        'actualizar' => [],           // solo inserta las nuevas
    ],
];

$MSJ_ERROR = "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";

$conObj   = new Conexion();
$con      = $conObj->conectar();
$conLocal = $conObj->conectar_local();

if (!$con || !$conLocal) {
    echo $MSJ_ERROR;
    exit(1);
}

foreach ([$con, $conLocal] as $pdo) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

try {
    // Evita dos sincronizaciones al mismo tiempo (no bloquea ninguna tabla).
    if (!$conLocal->query("SELECT pg_try_advisory_lock(" . LLAVE_CANDADO . ")")->fetchColumn()) {
        echo "Ya hay una sincronizaci&oacute;n en curso; se omite esta ejecuci&oacute;n.";
        exit(0);
    }

    // ---------------------------------------------------------------
    // 1. Leer el origen completo en una sola foto consistente (solo lectura).
    // ---------------------------------------------------------------
    $con->beginTransaction();
    $con->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY");
    $origen = [];
    foreach ($TABLAS as $tabla => $def) {
        $origen[$tabla] = leerTabla($con, $tabla, $def['pk'], $def['columnas']);
    }
    $idsHuellasOrigen = $con->query("SELECT cve_huella FROM huellas")->fetchAll(PDO::FETCH_COLUMN);
    $con->commit();

    // ---------------------------------------------------------------
    // 2. Aplicar en local, en el mismo orden que el original (respeta llaves foráneas).
    // ---------------------------------------------------------------
    $resumen = [];
    $resumen['personales'] = sincronizarTabla($conLocal, 'personales', $TABLAS['personales'], $origen['personales']);
    $resumen['huellas']    = sincronizarHuellas($con, $conLocal, $idsHuellasOrigen);
    foreach (['turnos', 'dias_laborables', 'personal_turnos', 'notificaciones', 'calendario_festivos', 'vacaciones'] as $tabla) {
        $resumen[$tabla] = sincronizarTabla($conLocal, $tabla, $TABLAS[$tabla], $origen[$tabla]);
    }

    // ---------------------------------------------------------------
    // 3. Avisar al servidor central (sentencia suelta, sin transacción larga).
    // ---------------------------------------------------------------
    $codigoOrigen = $conLocal->query("SELECT origen FROM origenes LIMIT 1")->fetchColumn();
    if ($codigoOrigen !== false) {
        notificaSincronizacion($con, $codigoOrigen);
    }

    $conLocal->query("SELECT pg_advisory_unlock(" . LLAVE_CANDADO . ")");

    foreach ($resumen as $tabla => $r) {
        echo "$tabla: {$r['insertados']} nuevos, {$r['actualizados']} actualizados, {$r['borrados']} borrados<br>\n";
    }
} catch (Exception $e) {
    foreach ([$con, $conLocal] as $pdo) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo htmlspecialchars($e->getMessage()) . "<br>\n";
    echo $MSJ_ERROR;
    exit(1);
}

// =====================================================================
// Funciones
// =====================================================================

/**
 * Normaliza un valor leído de PDO para poder compararlo y enviarlo como parámetro.
 * - null      -> null
 * - booleanos -> 't' / 'f'   (antes vDt() convertía false en NULL)
 * - ''        -> null        (igual que hacía vDt())
 * - 0 / '0'   -> '0'         (antes vDt() lo convertía en NULL, era un error)
 */
function normalizar($v)
{
    if ($v === null) {
        return null;
    }
    if (is_bool($v)) {
        return $v ? 't' : 'f';
    }
    if (is_resource($v)) {
        return stream_get_contents($v);
    }
    $v = (string)$v;
    return $v === '' ? null : $v;
}

/** Lee las columnas indicadas de una tabla, indexadas por su llave primaria. */
function leerTabla(PDO $pdo, $tabla, $pk, array $columnas)
{
    $filas = [];
    foreach ($pdo->query("SELECT " . implode(', ', $columnas) . " FROM $tabla") as $fila) {
        $fila = array_map('normalizar', $fila);
        $filas[(string)$fila[$pk]] = $fila;
    }
    return $filas;
}

/**
 * Compara origen vs local y escribe solo lo necesario:
 * INSERT de filas nuevas, UPDATE de filas que cambiaron, DELETE de sobrantes (si aplica).
 */
function sincronizarTabla(PDO $local, $tabla, array $def, array $filasOrigen)
{
    $pk         = $def['pk'];
    $columnas   = $def['columnas'];
    $actualizar = $def['actualizar'];

    $colsLocal  = array_values(array_unique(array_merge([$pk], $actualizar)));
    $filasLocal = leerTabla($local, $tabla, $pk, $colsLocal);

    $sqlInsert = "INSERT INTO $tabla (" . implode(', ', $columnas) . ") VALUES ("
               . implode(', ', array_fill(0, count($columnas), '?')) . ")";
    $sqlUpdate = $actualizar
        ? "UPDATE $tabla SET " . implode(', ', array_map(function ($c) { return "$c = ?"; }, $actualizar)) . " WHERE $pk = ?"
        : null;
    $sqlDelete = "DELETE FROM $tabla WHERE $pk = ?";

    $ops = [];
    $r = ['insertados' => 0, 'actualizados' => 0, 'borrados' => 0];

    foreach ($filasOrigen as $id => $fila) {
        if (!isset($filasLocal[$id])) {
            $ops[] = [$sqlInsert, valores($fila, $columnas)];
            $r['insertados']++;
        } elseif ($sqlUpdate !== null) {
            $actual = $filasLocal[$id];
            foreach ($actualizar as $c) {
                if ($actual[$c] !== $fila[$c]) {
                    $params   = valores($fila, $actualizar);
                    $params[] = $fila[$pk];
                    $ops[]    = [$sqlUpdate, $params];
                    $r['actualizados']++;
                    break;
                }
            }
        }
    }

    if (!empty($def['borrar_sobrantes'])) {
        foreach (array_keys(array_diff_key($filasLocal, $filasOrigen)) as $id) {
            $ops[] = [$sqlDelete, [$id]];
            $r['borrados']++;
        }
    }

    aplicarEnLotes($local, $ops);
    return $r;
}

/**
 * Huellas: solo se descargan del servidor las que faltan en local (antes se bajaban
 * TODAS las huellas binarias en cada ejecución). Se insertan como binario real
 * (PARAM_LOB), sin pg_escape_bytea ni SQL concatenado.
 */
function sincronizarHuellas(PDO $con, PDO $local, array $idsOrigen)
{
    $idsOrigen = array_map('strval', $idsOrigen);
    $idsLocal  = array_map('strval', $local->query("SELECT cve_huella FROM huellas")->fetchAll(PDO::FETCH_COLUMN));

    $nuevos    = array_values(array_diff($idsOrigen, $idsLocal));
    // Igual que el original: solo se borra si el origen trajo huellas.
    $sobrantes = count($idsOrigen) > 0 ? array_values(array_diff($idsLocal, $idsOrigen)) : [];

    $r = ['insertados' => 0, 'actualizados' => 0, 'borrados' => 0];

    $st = $con->prepare("SELECT cve_huella, cve_personal, desc_huella FROM huellas WHERE cve_huella = ANY(CAST(? AS bigint[]))");
    foreach (array_chunk($nuevos, 50) as $grupo) {
        $st->execute(['{' . implode(',', $grupo) . '}']);
        $ops = [];
        while ($f = $st->fetch(PDO::FETCH_ASSOC)) {
            $blob  = is_resource($f['desc_huella']) ? stream_get_contents($f['desc_huella']) : $f['desc_huella'];
            $ops[] = ["INSERT INTO huellas (cve_huella, cve_personal, desc_huella) VALUES (?, ?, ?)",
                      [$f['cve_huella'], $f['cve_personal'], $blob],
                      [2]];   // el parámetro 2 (base 0) va como binario
        }
        aplicarEnLotes($local, $ops);
        $r['insertados'] += count($ops);
    }

    $ops = [];
    foreach ($sobrantes as $id) {
        $ops[] = ["DELETE FROM huellas WHERE cve_huella = ?", [$id]];
    }
    aplicarEnLotes($local, $ops);
    $r['borrados'] = count($ops);

    return $r;
}

/** Devuelve los valores de $fila en el orden de $columnas. */
function valores(array $fila, array $columnas)
{
    $v = [];
    foreach ($columnas as $c) {
        $v[] = array_key_exists($c, $fila) ? $fila[$c] : null;
    }
    return $v;
}

/**
 * Ejecuta las operaciones en transacciones cortas de TAM_LOTE.
 * Cada transacción dura milisegundos; si encuentra una fila ocupada espera como
 * máximo STMT_TIMEOUT, deshace su lote y reintenta después, cediendo el paso al checador.
 *
 * Cada operación: [sql, params, indicesBinarios(opcional)]
 */
function aplicarEnLotes(PDO $pdo, array $ops)
{
    // 55P03 lock_not_available, 40P01 deadlock, 40001 serialization, 57014 statement timeout
    $reintentables = ['55P03', '40P01', '40001', '57014'];

    foreach (array_chunk($ops, TAM_LOTE) as $lote) {
        for ($intento = 1; ; $intento++) {
            $cache = [];
            try {
                $pdo->beginTransaction();
                $pdo->exec("SET LOCAL statement_timeout = '" . STMT_TIMEOUT . "'");

                foreach ($lote as $op) {
                    $sql    = $op[0];
                    $params = array_values($op[1]);
                    $lobs   = isset($op[2]) ? $op[2] : [];

                    if (!isset($cache[$sql])) {
                        $cache[$sql] = $pdo->prepare($sql);
                    }
                    $st = $cache[$sql];

                    foreach ($params as $i => $v) {
                        if ($v === null) {
                            $st->bindValue($i + 1, null, PDO::PARAM_NULL);
                        } elseif (in_array($i, $lobs, true)) {
                            $st->bindValue($i + 1, $v, PDO::PARAM_LOB);
                        } else {
                            $st->bindValue($i + 1, (string)$v, PDO::PARAM_STR);
                        }
                    }
                    $st->execute();
                }

                $pdo->commit();
                break;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!in_array($e->getCode(), $reintentables, true) || $intento >= MAX_REINTENTOS) {
                    throw $e;
                }
                usleep(500000 * $intento); // 0.5s, 1s... deja pasar a quien esté checando
            }
        }
    }
}

/** Marca en el servidor central la fecha de la última sincronización de este checador. */
function notificaSincronizacion(PDO $con, $origen)
{
    $up = $con->prepare("UPDATE check_activos SET fecha_actualizacion_general = now() WHERE nombre_origen = ?");
    $up->execute([$origen]);
    if ($up->rowCount() === 0) {
        $con->prepare("INSERT INTO check_activos (nombre_origen, fecha_actualizacion_general) VALUES (?, now())")
            ->execute([$origen]);
    }
}
