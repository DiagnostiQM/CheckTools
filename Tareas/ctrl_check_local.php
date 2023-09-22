<?php
include_once 'conexion.php';
$conObj = new Conexion();
$con = $conObj->conectar();
$conLocal = $conObj->conectar_local();
$con->beginTransaction();
$conLocal->beginTransaction();
$sqlCodigo = "select * from origenes";
$prvCodigo = $conLocal->prepare($sqlCodigo);
$prvCodigo->execute();
$datCodigo = $prvCodigo->fetch(PDO::FETCH_ASSOC);
if(!empty($datCodigo['codigo_check'])){
	$sqlMovimientos = "SELECT *, to_char(hora_marcada,'dd/mm/yyyy') hora_marcada_formateada FROM entradas_salidas
				WHERE hora_marcada::date > (now()::Date - CAST('7 days' AS INTERVAL))
				and estatus_entrada_salida not in ('VACACIONES', 'JUSTIFICADO','DIA FESTIVO')
				and COALESCE(codigo_check,'CHECK_PRODUCCION') != '".$datCodigo['codigo_check']."'
				ORDER BY hora_marcada asc";
	$prMov = $con->prepare($sqlMovimientos);
	$prMov->execute();
	foreach ($prMov as $llv => $vlr){
		$sqlVerifica =  " select count(*) total from entradas_salidas WHERE ".
						" cve_entrada_salida_origen = ".(!empty($vlr['cve_entrada_salida_origen'])?$vlr['cve_entrada_salida_origen']:$vlr['cve_entrada_salida'])." ".
						" and codigo_check = '".(!empty($vlr['codigo_check'])?$vlr['codigo_check']:'PRODUCCION')."' ";
		$prvVerifica = $conLocal->prepare($sqlVerifica);
		$prvVerifica->execute();
		$datVerifica = $prvVerifica->fetch(PDO::FETCH_ASSOC);
		if($datVerifica['total'] == '0'){
			// BUSCA POR CVE_PERSONA, HORA_MARCADA Y ESTATUS
			if($vlr['estatus_entrada_salida'] === 'SIN MOVIMIENTO ENTRADA'){
				$sqlExsLoc = "select * from entradas_salidas 
							WHERE cve_personal_turno = ".$vlr['cve_personal_turno']."
							and estatus_entrada_salida in ('PUNTUALIDAD AL ENTRAR','FALTA','RETARDO')
							and hora_marcada::date = to_date('".$vlr['hora_marcada_formateada']."','dd/mm/yyyy') ";
				$prvExsLoc = $conLocal->prepare($sqlExsLoc);
				$prvExsLoc->execute();
				$datExsLoc = $prvExsLoc->fetchAll(PDO::FETCH_ASSOC);

				foreach($datExsLoc as $k => $v){
					$sqlDelPro = "DELETE FROM entradas_salidas WHERE cve_entrada_salida = ".$vlr['cve_entrada_salida'];
					$prvDelPro = $con->prepare($sqlDelPro);
					$prvDelPro->execute();

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
								" ".vDt($v['hora_marcada']).", ".		" ".vDt($v['estatus_entrada_salida']).", ".		" ".vDt($v['cve_calendario']).", ".
								" ".vDt($v['cve_personal_turno']).", ".	" ".vDt($v['fecha_registro']).", ".				" 'ticdqmdb', ".
								" ".vDt($v['ip_registro']).", ".			" ".vDt($v['fecha_modificacion']).", ".			" 'ticdqmdb', ".
								" ".vDt($v['ip_modifico']).", ".			" ".vDt($v['estatus_registro']).", ".			" ".vDt($v['horas_extras']).", ".
								" ".vDt($v['observacion']).", ".			" ".vDt($v['cve_justificacion']).", ".			" ".vDt($v['cve_justificacion_pertur']).", ".
								" ".vDt($v['cve_vacacion']).", ".		" ".vDt($v['es_falta_entrada']).", ".			" ".vDt($v['dia_turno_especial']).", ".
								" ".vDt($v['origen']).", ".				" ".vDt($v['cve_det_turno_temporal']).", ".		" ".vDt($v['cve_entrada_salida']).", ".
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
											WHERE cve_entrada_salida = ".$v['cve_entrada_salida'];
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

			} else if($vlr['estatus_entrada_salida'] === 'SIN MOVIMIENTO SALIDA'){
				$sqlExsLoc = "select * from entradas_salidas 
							WHERE cve_personal_turno = ".$vlr['cve_personal_turno']."
							and estatus_entrada_salida in ('PUNTUALIDAD AL SALIR','SALIDA ANTICIPADA')
							and hora_marcada::date = to_date('".$vlr['hora_marcada_formateada']."','dd/mm/yyyy') ";
				$prvExsLoc = $conLocal->prepare($sqlExsLoc);
				$prvExsLoc->execute();
				$datExsLoc = $prvExsLoc->fetchAll(PDO::FETCH_ASSOC);
				
				foreach($datExsLoc as $k => $v){
					$sqlDelPro = "DELETE FROM entradas_salidas WHERE cve_entrada_salida = ".$vlr['cve_entrada_salida'];
					$prvDelPro = $con->prepare($sqlDelPro);
					$prvDelPro->execute();

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
								" ".vDt($v['hora_marcada']).", ".		" ".vDt($v['estatus_entrada_salida']).", ".		" ".vDt($v['cve_calendario']).", ".
								" ".vDt($v['cve_personal_turno']).", ".	" ".vDt($v['fecha_registro']).", ".				" 'ticdqmdb', ".
								" ".vDt($v['ip_registro']).", ".			" ".vDt($v['fecha_modificacion']).", ".			" 'ticdqmdb', ".
								" ".vDt($v['ip_modifico']).", ".			" ".vDt($v['estatus_registro']).", ".			" ".vDt($v['horas_extras']).", ".
								" ".vDt($v['observacion']).", ".			" ".vDt($v['cve_justificacion']).", ".			" ".vDt($v['cve_justificacion_pertur']).", ".
								" ".vDt($v['cve_vacacion']).", ".		" ".vDt($v['es_falta_entrada']).", ".			" ".vDt($v['dia_turno_especial']).", ".
								" ".vDt($v['origen']).", ".				" ".vDt($v['cve_det_turno_temporal']).", ".		" ".vDt($v['cve_entrada_salida']).", ".
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
											WHERE cve_entrada_salida = ".$v['cve_entrada_salida'];
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
			} else {
				$sqlInserta = 	"insert into entradas_salidas ( ".
				"cve_entrada_salida,			hora_marcada,		estatus_entrada_salida,		cve_calendario, ".
				"cve_personal_turno,			fecha_registro,		creado_por,					ip_registro, ".
				"fecha_modificacion,			modificado_por,		ip_modifico,				estatus_registro, ".
				"horas_extras,					observacion,		cve_justificacion,			cve_justificacion_pertur, ".
				"cve_vacacion,					es_falta_entrada,	dia_turno_especial,			origen, ".
				"cve_det_turno_temporal,		cve_entrada_salida_origen,						estatus, ".
				"codigo_check)  ".
				"VALUES ( ".
				"nextval('seq_entrada_salida'), ".
				" ".vDt($vlr['hora_marcada']).", ".		" ".vDt($vlr['estatus_entrada_salida']).", ".		" ".vDt($vlr['cve_calendario']).", ".
				" ".vDt($vlr['cve_personal_turno']).", ".	" ".vDt($vlr['fecha_registro']).", ".				" 'ticdqmdb', ".
				" ".vDt($vlr['ip_registro']).", ".			" ".vDt($vlr['fecha_modificacion']).", ".			" 'ticdqmdb', ".
				" ".vDt($vlr['ip_modifico']).", ".			" ".vDt($vlr['estatus_registro']).", ".			" ".vDt($vlr['horas_extras']).", ".
				" ".vDt($vlr['observacion']).", ".			" ".vDt($vlr['cve_justificacion']).", ".			" ".vDt($vlr['cve_justificacion_pertur']).", ".
				" ".vDt($vlr['cve_vacacion']).", ".		" ".vDt($vlr['es_falta_entrada']).", ".			" ".vDt($vlr['dia_turno_especial']).", ".
				" ".vDt($vlr['origen']).", ".				" ".vDt($vlr['cve_det_turno_temporal']).", ".		" ".vDt((!empty($vlr['cve_entrada_salida_origen'])?$vlr['cve_entrada_salida_origen']:$vlr['cve_entrada_salida'])).", ".
				" 'SINCRONIZADO',".vDt((!empty($vlr['codigo_check'])?$vlr['codigo_check']:'PRODUCCION')).") ";
				
				$prIns = $conLocal->prepare($sqlInserta);
				$exIns = $prIns->execute();
				
				if ( !$exIns ) {
					$msjerror	= $prIns->errorInfo();
					$con->rollBack();
					$conLocal->rollBack();
					var_dump($msjerror);
					//sleep(60);
					echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
					exit();
				}
			}
		}
	}
}

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
							fecha_actualiza_produccion = now() 
							where nombre_origen = '".$origen."' ";
		$prAc = $con->prepare($actualizaOrigen);
		$prAc->execute();
	} else {
		$insertaOrigen = "	insert into check_activos 
							(nombre_origen,fecha_actualiza_produccion) 
							values ('".$origen."',now())";
		$prIn = $con->prepare($insertaOrigen);
		$prIn->execute();
	}
}

?>