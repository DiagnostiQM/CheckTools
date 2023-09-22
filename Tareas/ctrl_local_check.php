<?php
include_once 'conexion.php';
$conObj = new Conexion();
$con = $conObj->conectar();
$conLocal = $conObj->conectar_local();

$con->beginTransaction();
$conLocal->beginTransaction();

$sqlMovimientos = "SELECT *, to_char(hora_marcada,'dd/mm/yyyy') hora_marcada_formateada FROM entradas_salidas
			WHERE hora_marcada::date > (now()::Date - CAST('7 days' AS INTERVAL))
			and estatus = 'PENDIENTE'
			ORDER BY hora_marcada asc";
$prMov = $conLocal->prepare($sqlMovimientos);
$prMov->execute();

$sqlCodigo = "select * from origenes";
$prvCodigo = $conLocal->prepare($sqlCodigo);
$prvCodigo->execute();
$datCodigo = $prvCodigo->fetch(PDO::FETCH_ASSOC);


foreach ($prMov as $llv => $vlr){

	if($vlr['estatus_entrada_salida'] == 'PUNTUALIDAD AL ENTRAR' || $vlr['estatus_entrada_salida'] == 'FALTA' || $vlr['estatus_entrada_salida'] == 'RETARDO'){
		$proVerEnt = 	"select cve_entrada_salida, count(*) existe_entrada from entradas_salidas ".
						"where estatus_entrada_salida = 'SIN MOVIMIENTO ENTRADA' ".
						"and cve_personal_turno = ".$vlr['cve_personal_turno']." ".
						"and hora_marcada::date = to_date('".$vlr['hora_marcada_formateada']."','dd/mm/yyyy') ".
						"group by cve_entrada_salida";
		$prVE = $con->prepare($proVerEnt);
		$prVE->execute();
		$dtVE = $prVE->fetchAll(PDO::FETCH_ASSOC);

		foreach($dtVE as $k => $v){
			$sqlDelPro = "DELETE FROM entradas_salidas WHERE cve_entrada_salida = ".$v['cve_entrada_salida'];
			$prvDelPro = $con->prepare($sqlDelPro);
			$prvDelPro->execute();
		}
	} else if($vlr['estatus_entrada_salida'] == 'PUNTUALIDAD AL SALIR' || $vlr['estatus_entrada_salida'] == 'SALIDA ANTICIPADA'){
		$proVerEnt = 	"select cve_entrada_salida, count(*) existe_entrada from entradas_salidas ".
						"where estatus_entrada_salida = 'SIN MOVIMIENTO SALIDA' ".
						"and cve_personal_turno = ".$vlr['cve_personal_turno']." ".
						"and hora_marcada::date = to_date('".$vlr['hora_marcada_formateada']."','dd/mm/yyyy') ".
						"group by cve_entrada_salida";
		$prVE = $con->prepare($proVerEnt);
		$prVE->execute();
		$dtVE = $prVE->fetchAll(PDO::FETCH_ASSOC);

		foreach($dtVE as $k => $v){
			$sqlDelPro = "DELETE FROM entradas_salidas WHERE cve_entrada_salida = ".$v['cve_entrada_salida'];
			$prvDelPro = $con->prepare($sqlDelPro);
			$prvDelPro->execute();
		}
	} 

		$sqlInserta = 	
			"insert into entradas_salidas ( ".
				"cve_entrada_salida,			hora_marcada,		estatus_entrada_salida,		cve_calendario, ".
				"cve_personal_turno,			fecha_registro,		creado_por,					ip_registro, ".
				"fecha_modificacion,			modificado_por,		ip_modifico,				estatus_registro, ".
				"horas_extras,					observacion,		cve_justificacion,			cve_justificacion_pertur, ".
				"cve_vacacion,					es_falta_entrada,	dia_turno_especial,			origen, ".
				"cve_det_turno_temporal,		cve_entrada_salida_origen,						estatus, codigo_check)  ".
			"VALUES ( ".
				"nextval('seq_entrada_salida'), ".
				" ".vDt($vlr['hora_marcada']).", ".		" ".vDt($vlr['estatus_entrada_salida']).", ".		" ".vDt($vlr['cve_calendario']).", ".
				" ".vDt($vlr['cve_personal_turno']).", ".	" ".vDt($vlr['fecha_registro']).", ".				" 'ticdqmdb', ".
				" ".vDt($vlr['ip_registro']).", ".			" ".vDt($vlr['fecha_modificacion']).", ".			" 'ticdqmdb', ".
				" ".vDt($vlr['ip_modifico']).", ".			" ".vDt($vlr['estatus_registro']).", ".			" ".vDt($vlr['horas_extras']).", ".
				" ".vDt($vlr['observacion']).", ".			" ".vDt($vlr['cve_justificacion']).", ".			" ".vDt($vlr['cve_justificacion_pertur']).", ".
				" ".vDt($vlr['cve_vacacion']).", ".		" ".vDt($vlr['es_falta_entrada']).", ".			" ".vDt($vlr['dia_turno_especial']).", ".
				" ".vDt($vlr['origen']).", ".				" ".vDt($vlr['cve_det_turno_temporal']).", ".		" ".vDt($vlr['cve_entrada_salida']).", ".
				" 'SINCRONIZADO', ".vDT($datCodigo['codigo_check']).")";
		$prIns = $con->prepare($sqlInserta);
		$exIns = $prIns->execute();
		
		if ( !$exIns ) {
			$msjerror	= $prIns->errorInfo();
			$con->rollBack();
			$conLocal->rollBack();
			var_dump($msjerror);
			echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
			exit();
		}
		$sqlSincronizado = "UPDATE entradas_salidas set 
							estatus = 'SINCRONIZADO'
							WHERE cve_entrada_salida = ".$vlr['cve_entrada_salida'];
		$prSin = $conLocal->prepare($sqlSincronizado);
		$exSin = $prSin->execute();
		if ( !$exSin ) {
			$msjerror	= $prSin->errorInfo();
			$con->rollBack();
			$conLocal->rollBack();
			var_dump($msjerror);
			echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (local)";
			exit();
		}
}

$sqlHL = "SELECT * FROM notificaciones_entradas_salidas WHERE sincronizado = false";
$prvHL = $conLocal->prepare($sqlHL);
$prvHL->execute();
$prvHL->bindColumn('nombre_completo', $nombre_incidencia, PDO::PARAM_STR);
$prvHL->bindColumn('tipo_incidencia', $tipo_incidencia, PDO::PARAM_STR);
$prvHL->bindColumn('fecha_notificacion', $fecha_incidencia, PDO::PARAM_STR);
$prvHL->bindColumn('origen', $origen_incidencia, PDO::PARAM_STR);
$prvHL->bindColumn('imagen', $fileData, PDO::PARAM_STR);

while($prvHL->fetch(\PDO::FETCH_BOUND)){

	$buffer = pg_escape_bytea($fileData);
	$sqlDinamico = 	" INSERT INTO notificaciones_entradas_salidas ".
					"(nombre_completo, tipo_incidencia, fecha_notificacion, origen, imagen) ".
					" values ('$nombre_incidencia','$tipo_incidencia','$fecha_incidencia','$origen_incidencia','$buffer')";
	$prDinamicoHL = $con->prepare($sqlDinamico);
	$o = $prDinamicoHL->execute();

	$sqlSin = "update notificaciones_entradas_salidas set sincronizado = true where sincronizado = false";
	$preSin = $conLocal->prepare($sqlSin);
	$preSin->execute();

	if ( !$o ) {
	$msjerror	= $prDinamicoHL->errorInfo();
	$con->rollBack();
	$conLocal->rollBack();
	echo $sqlDinamico;
	var_dump($msjerror);
	echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
	exit();
	}
}
	$sqlSin = "update notificaciones_entradas_salidas set sincronizado = true where sincronizado = false";
	$preSin = $conLocal->prepare($sqlSin);
	$preSin->execute();

notifica_sincronizacion($con,$datCodigo['origen']);

$con->commit();
$conLocal->commit();

function vDt($pdato){
	$dtFinal = "";
	if(empty($pdato)){
		$dtFinal = " NULL ";
	} else {
		$dtFinal = " '".$pdato."' ";
	}
	return $dtFinal;
}

function notifica_sincronizacion($con,$origen){
	$existeOrigen = "select count(*) existe from check_activos WHERE nombre_origen = '".$origen."'";
	$prEx = $con->prepare($existeOrigen);
	$prEx->execute();
	$rwEx = $prEx->fetch(PDO::FETCH_ASSOC);

	if($rwEx['existe'] != '0'){
		$actualizaOrigen = "update check_activos set 
							fecha_actualiza_local = now() 
							where nombre_origen = '".$origen."' ";
		$prAc = $con->prepare($actualizaOrigen);
		$prAc->execute();
	} else {
		$insertaOrigen = "	insert into check_activos 
							(nombre_origen,fecha_actualiza_local) 
							values ('".$origen."',now())";
		$prIn = $con->prepare($insertaOrigen);
		$prIn->execute();
	}
}

?>