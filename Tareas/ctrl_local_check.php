<?php
/**
 * Sube las entradas/salidas del checador local al servidor central.
 * Compatible con PHP 5.6 y PostgreSQL 9.2.
 *
 * Cambios principales contra la versión anterior:
 *  - Trabaja por lotes de TAM_LOTE movimientos: un DELETE y un INSERT por lote
 *    en lugar de un SELECT + DELETE(s) + INSERT + UPDATE por cada movimiento.
 *  - Las búsquedas por fecha usan rangos (hora_marcada >= dia AND < dia+1) en vez de
 *    hora_marcada::date = ..., para que PostgreSQL pueda usar índices.
 *  - Transacciones cortas por lote (nada queda bloqueado durante toda la ejecución).
 *  - Es idempotente: antes de insertar revisa si el movimiento ya se subió
 *    (codigo_check + cve_entrada_salida_origen), así un corte a medias no duplica registros.
 *  - Notificaciones: solo marca como sincronizadas las que realmente subió
 *    (antes marcaba TODAS las pendientes, incluidas las que llegaban mientras corría).
 *  - Imágenes como binario real (PARAM_LOB) y SQL con parámetros (nombres con apóstrofo ya no fallan).
 */
include_once 'conexion.php';

const TAM_LOTE            = 100;     // movimientos por lote
const TAM_LOTE_NOTIF      = 20;      // notificaciones por lote (llevan imagen)
const TIMEOUT_LOCAL       = '5s';
const TIMEOUT_CENTRAL     = '60s';
const MAX_REINTENTOS      = 3;
const LLAVE_CANDADO       = 7310525; // distinto al del script de sincronización de tablas

$MSJ_ERROR = "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";

$conObj   = new Conexion();
$con      = $conObj->conectar();
$conLocal = $conObj->conectar_local();

if (!$con || !$conLocal) {
    echo $MSJ_ERROR;
    exit(1);
}

foreach (array($con, $conLocal) as $pdo) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

try {
    if (!$conLocal->query("SELECT pg_try_advisory_lock(" . LLAVE_CANDADO . ")")->fetchColumn()) {
        echo "Ya hay una subida de movimientos en curso; se omite esta ejecuci&oacute;n.";
        exit(0);
    }

    $datCodigo = $conLocal->query("SELECT origen, codigo_check FROM origenes LIMIT 1")->fetch();
    if (!$datCodigo) {
        throw new Exception("No hay registro en la tabla origenes.");
    }
    $codigoCheck = normalizar($datCodigo['codigo_check']);

    // -------------------------------------------------------------
    // 1. Movimientos pendientes (lectura simple, no bloquea nada).
    //    Mismo filtro que antes: fecha > hoy - 21 días, escrito como rango.
    // -------------------------------------------------------------
    $movimientos = $conLocal->query(
        "SELECT *, to_char(hora_marcada, 'YYYY-MM-DD') AS fecha_marcada
           FROM entradas_salidas
          WHERE hora_marcada >= current_date - 20
            AND estatus = 'PENDIENTE'
          ORDER BY hora_marcada ASC"
    )->fetchAll();

    $totMov = 0;
    $totOmitidos = 0;
    foreach (array_chunk($movimientos, TAM_LOTE) as $lote) {
        $r = subirLoteMovimientos($con, $conLocal, $lote, $codigoCheck);
        $totMov      += $r['subidos'];
        $totOmitidos += $r['ya_existian'];
    }

    // -------------------------------------------------------------
    // 2. Notificaciones con imagen.
    // -------------------------------------------------------------
    $totNotif = subirNotificaciones($con, $conLocal);

    // -------------------------------------------------------------
    // 3. Avisar al central.
    // -------------------------------------------------------------
    notificaSincronizacion($con, $datCodigo['origen']);

    $conLocal->query("SELECT pg_advisory_unlock(" . LLAVE_CANDADO . ")");

    echo "Movimientos subidos: $totMov (ya exist&iacute;an en el central: $totOmitidos)<br>\n";
    echo "Notificaciones subidas: $totNotif<br>\n";
} catch (Exception $e) {
    foreach (array($con, $conLocal) as $pdo) {
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

function subirLoteMovimientos(PDO $con, PDO $conLocal, array $lote, $codigoCheck)
{
    $estatusEntrada = array('PUNTUALIDAD AL ENTRAR', 'FALTA', 'RETARDO');
    $estatusSalida  = array('PUNTUALIDAD AL SALIR', 'SALIDA ANTICIPADA');

    $idsLocales = array();
    foreach ($lote as $m) {
        $idsLocales[] = (string)$m['cve_entrada_salida'];
    }

    $resultado = array('subidos' => 0, 'ya_existian' => 0);

    enTransaccion($con, TIMEOUT_CENTRAL, function (PDO $con) use ($lote, $idsLocales, $codigoCheck, $estatusEntrada, $estatusSalida, &$resultado) {

        // a) ¿Cuáles de este lote ya están en el central? (por si una ejecución anterior se cortó)
        $yaSubidos = array();
        if ($codigoCheck !== null) {
            $st = $con->prepare(
                "SELECT cve_entrada_salida_origen FROM entradas_salidas
                  WHERE codigo_check = ? AND cve_entrada_salida_origen IN (" . marcadores(count($idsLocales)) . ")"
            );
            ejecutar($st, array_merge(array($codigoCheck), $idsLocales));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $yaSubidos[(string)$id] = true;
            }
        }

        // b) INSERT de todos los movimientos nuevos del lote en una sola sentencia.
        $filas  = array();
        $params = array();
        foreach ($lote as $m) {
            if (isset($yaSubidos[(string)$m['cve_entrada_salida']])) {
                $resultado['ya_existian']++;
                continue;
            }
            $filas[] = "(nextval('seq_entrada_salida'), " . marcadores(23) . ")";
            $params  = array_merge($params, array(
                $m['hora_marcada'], $m['estatus_entrada_salida'], $m['cve_calendario'],
                $m['cve_personal_turno'], $m['fecha_registro'], 'ticdqmdb',
                $m['ip_registro'], $m['fecha_modificacion'], 'ticdqmdb',
                $m['ip_modifico'], $m['estatus_registro'], $m['horas_extras'],
                $m['observacion'], $m['cve_justificacion'], $m['cve_justificacion_pertur'],
                $m['cve_vacacion'], $m['es_falta_entrada'], $m['dia_turno_especial'],
                $m['origen'], $m['cve_det_turno_temporal'], $m['cve_entrada_salida'],
                'SINCRONIZADO', $codigoCheck
            ));
        }

        if ($filas) {
            $sql = "INSERT INTO entradas_salidas (
                        cve_entrada_salida, hora_marcada, estatus_entrada_salida, cve_calendario,
                        cve_personal_turno, fecha_registro, creado_por, ip_registro,
                        fecha_modificacion, modificado_por, ip_modifico, estatus_registro,
                        horas_extras, observacion, cve_justificacion, cve_justificacion_pertur,
                        cve_vacacion, es_falta_entrada, dia_turno_especial, origen,
                        cve_det_turno_temporal, cve_entrada_salida_origen, estatus, codigo_check)
                    VALUES " . implode(",\n", $filas);
            ejecutar($con->prepare($sql), $params);
            $resultado['subidos'] = count($filas);
        }

        // c) Un solo DELETE para los "SIN MOVIMIENTO" que quedan cubiertos por una marca real.
        //    Va después del INSERT: solo borra registros SIN MOVIMIENTO, nunca los recién insertados.
        $condiciones = array();
        $paramsDel   = array();
        $vistos      = array();
        foreach ($lote as $m) {
            if (in_array($m['estatus_entrada_salida'], $estatusEntrada, true)) {
                $tipo = 'SIN MOVIMIENTO ENTRADA';
            } elseif (in_array($m['estatus_entrada_salida'], $estatusSalida, true)) {
                $tipo = 'SIN MOVIMIENTO SALIDA';
            } else {
                continue;
            }
            $clave = $m['cve_personal_turno'] . '|' . $m['fecha_marcada'] . '|' . $tipo;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $condiciones[] = "(cve_personal_turno = ? AND estatus_entrada_salida = ?
                               AND hora_marcada >= CAST(? AS date) AND hora_marcada < CAST(? AS date) + 1)";
            array_push($paramsDel, $m['cve_personal_turno'], $tipo, $m['fecha_marcada'], $m['fecha_marcada']);
        }
        if ($condiciones) {
            ejecutar($con->prepare("DELETE FROM entradas_salidas WHERE " . implode(' OR ', $condiciones)), $paramsDel);
        }
    });

    // d) Marcar en local (transacción corta, una sola sentencia).
    //    Si esto fallara, la siguiente ejecución NO duplica: el paso (a) los detecta.
    enTransaccion($conLocal, TIMEOUT_LOCAL, function (PDO $conLocal) use ($idsLocales) {
        $st = $conLocal->prepare(
            "UPDATE entradas_salidas SET estatus = 'SINCRONIZADO'
              WHERE estatus = 'PENDIENTE' AND cve_entrada_salida IN (" . marcadores(count($idsLocales)) . ")"
        );
        ejecutar($st, $idsLocales);
    });

    return $resultado;
}

/**
 * Sube las notificaciones pendientes. La tabla local no muestra llave primaria en el
 * script original, así que se identifica cada fila por su ctid y solo se marcan
 * como sincronizadas las filas que realmente se subieron.
 * (Si la tabla tiene una llave, conviene usarla en lugar de ctid.)
 */
function subirNotificaciones(PDO $con, PDO $conLocal)
{
    $filasIds = $conLocal->query(
        "SELECT ctid::text FROM notificaciones_entradas_salidas WHERE sincronizado = false"
    )->fetchAll(PDO::FETCH_COLUMN);

    $total = 0;
    foreach (array_chunk($filasIds, TAM_LOTE_NOTIF) as $grupo) {
        $st = $conLocal->prepare(
            "SELECT ctid::text AS fila, nombre_completo, tipo_incidencia, fecha_notificacion, origen, imagen
               FROM notificaciones_entradas_salidas
              WHERE sincronizado = false AND ctid IN (" . implode(', ', array_fill(0, count($grupo), 'CAST(? AS tid)')) . ")"
        );
        ejecutar($st, $grupo);
        $notifs = $st->fetchAll();
        if (!$notifs) {
            continue;
        }

        enTransaccion($con, TIMEOUT_CENTRAL, function (PDO $con) use ($notifs) {
            $ins = $con->prepare(
                "INSERT INTO notificaciones_entradas_salidas
                    (nombre_completo, tipo_incidencia, fecha_notificacion, origen, imagen)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($notifs as $n) {
                $imagen = is_resource($n['imagen']) ? stream_get_contents($n['imagen']) : $n['imagen'];
                ejecutar($ins, array($n['nombre_completo'], $n['tipo_incidencia'], $n['fecha_notificacion'], $n['origen'], $imagen), array(4));
            }
        });

        $subidas = array();
        foreach ($notifs as $n) {
            $subidas[] = $n['fila'];
        }
        enTransaccion($conLocal, TIMEOUT_LOCAL, function (PDO $conLocal) use ($subidas) {
            $st = $conLocal->prepare(
                "UPDATE notificaciones_entradas_salidas SET sincronizado = true
                  WHERE ctid IN (" . implode(', ', array_fill(0, count($subidas), 'CAST(? AS tid)')) . ")"
            );
            ejecutar($st, $subidas);
        });

        $total += count($notifs);
    }
    return $total;
}

/** Ejecuta $fn dentro de una transacción corta con statement_timeout; reintenta si encuentra bloqueo. */
function enTransaccion(PDO $pdo, $timeout, $fn)
{
    $reintentables = array('55P03', '40P01', '40001', '57014');
    for ($intento = 1; ; $intento++) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL statement_timeout = '" . $timeout . "'");
            $fn($pdo);
            $pdo->commit();
            return;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!in_array($e->getCode(), $reintentables, true) || $intento >= MAX_REINTENTOS) {
                throw $e;
            }
            usleep(500000 * $intento);
        }
    }
}

/** Liga parámetros (null, texto o binario) y ejecuta. */
function ejecutar(PDOStatement $st, array $params, array $indicesBinarios = array())
{
    foreach (array_values($params) as $i => $v) {
        if (in_array($i, $indicesBinarios, true)) {
            if ($v === null) {
                $st->bindValue($i + 1, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue($i + 1, $v, PDO::PARAM_LOB);
            }
            continue;
        }
        $v = normalizar($v);
        if ($v === null) {
            $st->bindValue($i + 1, null, PDO::PARAM_NULL);
        } else {
            $st->bindValue($i + 1, $v, PDO::PARAM_STR);
        }
    }
    $st->execute();
}

/**
 * - null / ''  -> NULL (como hacía vDt)
 * - booleanos  -> 't' / 'f'  (vDt convertía false en NULL)
 * - 0 / '0'    -> '0'        (vDt lo convertía en NULL)
 */
function normalizar($v)
{
    if ($v === null) {
        return null;
    }
    if (is_bool($v)) {
        return $v ? 't' : 'f';
    }
    $v = (string)$v;
    return $v === '' ? null : $v;
}

function marcadores($n)
{
    return implode(', ', array_fill(0, $n, '?'));
}

function notificaSincronizacion(PDO $con, $origen)
{
    $up = $con->prepare("UPDATE check_activos SET fecha_actualiza_local = now() WHERE nombre_origen = ?");
    $up->execute(array($origen));
    if ($up->rowCount() === 0) {
        $con->prepare("INSERT INTO check_activos (nombre_origen, fecha_actualiza_local) VALUES (?, now())")
            ->execute(array($origen));
    }
}
