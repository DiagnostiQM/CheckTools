<?php
/**
 * Misma lógica y mismo flujo que la versión original; solo cambios de velocidad:
 *  1. Filtros por fecha como rango (hora_marcada >= X) en lugar de hora_marcada::date,
 *     para que PostgreSQL pueda usar índices. El resultado es el mismo.
 *  2. Del central primero se bajan solo columnas clave; los registros completos se piden
 *     únicamente para los que sí se van a copiar.
 *  3. La verificación "¿ya existe en local?" se hace con pocas consultas (IN de 1000)
 *     en lugar de una consulta por movimiento.
 *  4. Sentencias preparadas una sola vez y reutilizadas (antes se armaba y preparaba
 *     un SQL nuevo por cada fila).
 *  5. Las copias a local se insertan en bloques de varias filas por sentencia.
 *     Antes de revisar cada SIN MOVIMIENTO se insertan las copias pendientes, para que
 *     la consulta vea exactamente lo mismo que veía la versión original.
 *
 * Se conservan: las dos transacciones abiertas durante todo el proceso, el orden por
 * hora_marcada, el criterio de vDt() (valor vacío = NULL) y los mismos mensajes de error.
 */
include_once 'conexion.php';

const TAM_LOTE = 200;

$MSJ_ERROR = "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";

$conObj   = new Conexion();
$con      = $conObj->conectar();
$conLocal = $conObj->conectar_local();

$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conLocal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$con->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$conLocal->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$SQL_INSERT_COLUMNAS =
    "INSERT INTO entradas_salidas (
        cve_entrada_salida, hora_marcada, estatus_entrada_salida, cve_calendario,
        cve_personal_turno, fecha_registro, creado_por, ip_registro,
        fecha_modificacion, modificado_por, ip_modifico, estatus_registro,
        horas_extras, observacion, cve_justificacion, cve_justificacion_pertur,
        cve_vacacion, es_falta_entrada, dia_turno_especial, origen,
        cve_det_turno_temporal, cve_entrada_salida_origen, estatus, codigo_check)
     VALUES ";
$SQL_FILA = "(nextval('seq_entrada_salida'), " . implode(', ', array_fill(0, 23, '?')) . ")";

$totCopiados    = 0; // movimientos del central copiados a local
$totSinMovBorr  = 0; // SIN MOVIMIENTO borrados en el central
$totSubidos     = 0; // marcas locales subidas al central

try {
    $con->beginTransaction();
    $conLocal->beginTransaction();

    $datCodigo = $conLocal->query("select * from origenes")->fetch();

    if (!empty($datCodigo['codigo_check'])) {
        $codigoLocal = $datCodigo['codigo_check'];

        // ------------------------------------------------------------
        // 1. Movimientos del central: solo columnas clave, mismo filtro y orden.
        //    hora_marcada::date > now()::date - 21 días  ==  hora_marcada >= current_date - 20
        // ------------------------------------------------------------
        $st = $con->prepare(
            "SELECT cve_entrada_salida, cve_entrada_salida_origen, codigo_check,
                    estatus_entrada_salida, cve_personal_turno,
                    to_char(hora_marcada, 'YYYY-MM-DD') AS fecha
               FROM entradas_salidas
              WHERE hora_marcada >= current_date - 20
                AND estatus_entrada_salida NOT IN ('VACACIONES', 'JUSTIFICADO', 'DIA FESTIVO')
                AND COALESCE(codigo_check, 'CHECK_PRODUCCION') != ?
              ORDER BY hora_marcada ASC"
        );
        $st->execute(array($codigoLocal));
        $central = $st->fetchAll();

        // Mismo criterio que el original para cve_entrada_salida_origen y codigo_check.
        $origenes = array();
        foreach ($central as $i => $c) {
            $central[$i]['_origen'] = !empty($c['cve_entrada_salida_origen']) ? (string)$c['cve_entrada_salida_origen'] : (string)$c['cve_entrada_salida'];
            $central[$i]['_codigo'] = !empty($c['codigo_check']) ? (string)$c['codigo_check'] : 'PRODUCCION';
            $origenes[$central[$i]['_origen']] = true;
        }

        // ------------------------------------------------------------
        // 2. ¿Cuáles ya existen en local? (misma condición que el SELECT count(*) original)
        // ------------------------------------------------------------
        $existentes = array();
        foreach (array_chunk(array_map('strval', array_keys($origenes)), 1000) as $grupo) {
            $st = $conLocal->prepare(
                "SELECT codigo_check, cve_entrada_salida_origen FROM entradas_salidas
                  WHERE cve_entrada_salida_origen IN (" . marcadores(count($grupo)) . ")"
            );
            $st->execute($grupo);
            foreach ($st->fetchAll() as $f) {
                $existentes[$f['codigo_check'] . '|' . $f['cve_entrada_salida_origen']] = true;
            }
        }

        // ------------------------------------------------------------
        // 3. Recorrido en el mismo orden que el original para decidir qué hacer.
        // ------------------------------------------------------------
        $acciones  = array();
        $idsCopiar = array();
        foreach ($central as $c) {
            $clave = $c['_codigo'] . '|' . $c['_origen'];
            if (isset($existentes[$clave])) {
                continue;
            }
            $est = $c['estatus_entrada_salida'];
            if ($est === 'SIN MOVIMIENTO ENTRADA' || $est === 'SIN MOVIMIENTO SALIDA') {
                $acciones[] = array('sinmov', $c);
            } else {
                $acciones[] = array('copiar', $c);
                $idsCopiar[] = (string)$c['cve_entrada_salida'];
                // En el original, la copia recién insertada ya se veía en la siguiente verificación.
                $existentes[$clave] = true;
            }
        }

        // ------------------------------------------------------------
        // 4. Registros completos solo de los que se van a copiar.
        // ------------------------------------------------------------
        $completos = array();
        foreach (array_chunk($idsCopiar, 500) as $grupo) {
            $st = $con->prepare("SELECT * FROM entradas_salidas WHERE cve_entrada_salida IN (" . marcadores(count($grupo)) . ")");
            $st->execute($grupo);
            foreach ($st->fetchAll() as $f) {
                $completos[(string)$f['cve_entrada_salida']] = $f;
            }
        }

        // ------------------------------------------------------------
        // 5. Sentencias preparadas una sola vez.
        // ------------------------------------------------------------
        $stReales = array(
            'SIN MOVIMIENTO ENTRADA' => $conLocal->prepare(
                "SELECT * FROM entradas_salidas
                  WHERE cve_personal_turno = ?
                    AND estatus_entrada_salida IN ('PUNTUALIDAD AL ENTRAR', 'FALTA', 'RETARDO')
                    AND hora_marcada >= CAST(? AS date) AND hora_marcada < CAST(? AS date) + 1"
            ),
            'SIN MOVIMIENTO SALIDA' => $conLocal->prepare(
                "SELECT * FROM entradas_salidas
                  WHERE cve_personal_turno = ?
                    AND estatus_entrada_salida IN ('PUNTUALIDAD AL SALIR', 'SALIDA ANTICIPADA')
                    AND hora_marcada >= CAST(? AS date) AND hora_marcada < CAST(? AS date) + 1"
            ),
        );
        $stDelCentral = $con->prepare("DELETE FROM entradas_salidas WHERE cve_entrada_salida = ?");
        $stInsCentral = $con->prepare($SQL_INSERT_COLUMNAS . $SQL_FILA);
        $stSinLocal   = $conLocal->prepare("UPDATE entradas_salidas SET estatus = 'SINCRONIZADO' WHERE cve_entrada_salida = ?");

        // ------------------------------------------------------------
        // 6. Ejecutar en orden.
        // ------------------------------------------------------------
        $pendientes = array();
        foreach ($acciones as $a) {
            $c = $a[1];

            if ($a[0] === 'copiar') {
                $id = (string)$c['cve_entrada_salida'];
                if (!isset($completos[$id])) {
                    continue;
                }
                $pendientes[] = valoresMovimiento($completos[$id], $c['_origen'], $c['_codigo']);
                $totCopiados++;
                if (count($pendientes) >= TAM_LOTE) {
                    insertarVarios($conLocal, $SQL_INSERT_COLUMNAS, $SQL_FILA, $pendientes);
                    $pendientes = array();
                }
                continue;
            }

            // SIN MOVIMIENTO: primero se insertan las copias pendientes para que la búsqueda
            // en local vea lo mismo que veía el original.
            if ($pendientes) {
                insertarVarios($conLocal, $SQL_INSERT_COLUMNAS, $SQL_FILA, $pendientes);
                $pendientes = array();
            }

            $st = $stReales[$c['estatus_entrada_salida']];
            ejecutar($st, array($c['cve_personal_turno'], $c['fecha'], $c['fecha']));
            $datExsLoc = $st->fetchAll();
            if ($datExsLoc) {
                $totSinMovBorr++;
                $totSubidos += count($datExsLoc);
            }

            foreach ($datExsLoc as $v) {
                ejecutar($stDelCentral, array($c['cve_entrada_salida']));
                ejecutar($stInsCentral, valoresMovimiento($v, $v['cve_entrada_salida'], $codigoLocal));
                ejecutar($stSinLocal, array($v['cve_entrada_salida']));
            }
        }
        if ($pendientes) {
            insertarVarios($conLocal, $SQL_INSERT_COLUMNAS, $SQL_FILA, $pendientes);
        }
    }

    notifica_sincronizacion($con, $datCodigo['origen']);

    $con->commit();
    $conLocal->commit();

    echo "Movimientos copiados a local: $totCopiados<br>\n";
    echo "SIN MOVIMIENTO borrados en central: $totSinMovBorr (marcas locales subidas: $totSubidos)<br>\n";
} catch (Exception $e) {
    if ($con->inTransaction()) {
        $con->rollBack();
    }
    if ($conLocal->inTransaction()) {
        $conLocal->rollBack();
    }
    var_dump($e->getMessage());
    echo $MSJ_ERROR;
    exit();
}

// =====================================================================
// Funciones
// =====================================================================

/** Los 23 valores del INSERT, en el mismo orden y con las mismas constantes que el original. */
function valoresMovimiento(array $m, $origenId, $codigo)
{
    return array(
        $m['hora_marcada'], $m['estatus_entrada_salida'], $m['cve_calendario'],
        $m['cve_personal_turno'], $m['fecha_registro'], 'ticdqmdb',
        $m['ip_registro'], $m['fecha_modificacion'], 'ticdqmdb',
        $m['ip_modifico'], $m['estatus_registro'], $m['horas_extras'],
        $m['observacion'], $m['cve_justificacion'], $m['cve_justificacion_pertur'],
        $m['cve_vacacion'], $m['es_falta_entrada'], $m['dia_turno_especial'],
        $m['origen'], $m['cve_det_turno_temporal'], $origenId,
        'SINCRONIZADO', $codigo
    );
}

/** INSERT de varias filas en una sola sentencia. */
function insertarVarios(PDO $pdo, $sqlColumnas, $sqlFila, array $filas)
{
    $params = array();
    foreach ($filas as $f) {
        foreach ($f as $v) {
            $params[] = $v;
        }
    }
    $sql = $sqlColumnas . implode(",\n", array_fill(0, count($filas), $sqlFila));
    ejecutar($pdo->prepare($sql), $params);
}

/** Liga parámetros con el mismo criterio que vDt(): si el valor está vacío se manda NULL. */
function ejecutar(PDOStatement $st, array $params)
{
    foreach (array_values($params) as $i => $v) {
        if (empty($v)) {
            $st->bindValue($i + 1, null, PDO::PARAM_NULL);
        } else {
            $st->bindValue($i + 1, (string)$v, PDO::PARAM_STR);
        }
    }
    $st->execute();
}

function marcadores($n)
{
    return implode(', ', array_fill(0, $n, '?'));
}

function notifica_sincronizacion($con, $origen)
{
    $prEx = $con->prepare("select count(*) existe from check_activos WHERE nombre_origen = ?");
    $prEx->execute(array($origen));
    $rwEx = $prEx->fetch(PDO::FETCH_ASSOC);

    if ($rwEx['existe'] != '0') {
        $prAc = $con->prepare("update check_activos set fecha_actualiza_produccion = now() where nombre_origen = ?");
        $prAc->execute(array($origen));
    } else {
        $prIn = $con->prepare("insert into check_activos (nombre_origen, fecha_actualiza_produccion) values (?, now())");
        $prIn->execute(array($origen));
    }
}
