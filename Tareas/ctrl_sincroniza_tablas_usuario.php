<?php
include_once 'conexion.php';
$conObj = new Conexion();

$conLocal = $conObj->conectar_local();
$conLocal->beginTransaction();
/*
$sqlSincroniza = "select * from ejecutar_actualizacion where ejecutar_actualizacion = true";
$prSincroniza = $conLocal->prepare($sqlSincroniza);
$sin = $prSincroniza->execute();
$datSin = $prSincroniza->fetch(PDO::FETCH_ASSOC);

if($datSin['ejecutar_actualizacion'] !== true || empty($datSin['ejecutar_actualizacion']) ){
	$conLocal->rollBack();
	exit();
}
*/
$con = $conObj->conectar();
$con->beginTransaction();

$sqlPR = "select * from personales";
$prvPR = $con->prepare($sqlPR);
$prvPR->execute();

foreach ($prvPR as $cve_pr => $val_pr){

	$sqlExiste = "SELECT count(*) total FROM personales WHERE cve_personal = ".vDt($val_pr['cve_personal'])." ";
	$prExistePR = $conLocal->prepare($sqlExiste);
	$n = $prExistePR->execute();
    $datExiste = $prExistePR->fetch(PDO::FETCH_ASSOC);

	if ( !$n ) {
		$msjerror	= $prExistePR->errorInfo();
		$con->rollBack();
		$conLocal->rollBack();
		echo $sqlExiste;
		var_dump($msjerror);
		echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
		exit();
	}

    if($datExiste['total'] == '0'){
        $sqlDinamico = 	" INSERT INTO personales( ".
						" cve_personal, nombre, paterno, materno, rfc, cve_subarea, fecha_registro, creado_por,  ".
						" ip_registro, fecha_modificacion, modificado_por, ip_modifico, estatus_registro,  ".
						" fecha_nacimiento, sexo, numero_oficina, correo_electronico, estatus_personal,  ".
						" cve_puesto, cve_marca, nss, num_empleado, url_foto, cve_estado, cve_sucursal, clave_acceso)  ".
						" VALUES  ".
						" (".vDt($val_pr['cve_personal']).", ".vDt($val_pr['nombre']).", ".vDt($val_pr['paterno']).", ".vDt($val_pr['materno']).", ".
						vDt($val_pr['rfc']).", ".vDt($val_pr['cve_subarea']).", ".vDt($val_pr['fecha_registro']).", ".vDt($val_pr['creado_por']).", ".
						vDt($val_pr['ip_registro']).", ".vDt($val_pr['fecha_modificacion']).", ".vDt($val_pr['modificado_por']).", ".vDt($val_pr['ip_modifico']).", ".
						vDt($val_pr['estatus_registro']).", ".vDt($val_pr['fecha_nacimiento']).", ".vDt($val_pr['sexo']).", ".vDt($val_pr['numero_oficina']).", ".
						vDt($val_pr['correo_electronico']).", ".vDt($val_pr['estatus_personal']).", ".vDt($val_pr['cve_puesto']).", ".vDt($val_pr['cve_marca']).", ".
						vDt($val_pr['nss']).", ".vDt($val_pr['num_empleado']).", ".vDt($val_pr['url_foto']).", ".vDt($val_pr['cve_estado']).", ".
						vDt($val_pr['cve_sucursal']).", ".vDt($val_pr['clave_acceso']).")";
    } else {
        $sqlDinamico = 	" UPDATE personales set ".
						" estatus_registro = ".vDt($val_pr['estatus_registro']).", ".
						" nss = ".vDt($val_pr['nss']).", ".
						" num_empleado = ".vDt($val_pr['num_empleado']).", ".
						" clave_acceso = ".vDt($val_pr['clave_acceso']).", ".
						" estatus_personal = ".vDt($val_pr['estatus_personal'])." ".
						" WHERE cve_personal =".vDt($val_pr['cve_personal'])." ";
    }
	$prDinamicoPR = $conLocal->prepare($sqlDinamico);
	$m = $prDinamicoPR->execute();

	if ( !$m ) {
		$msjerror	= $prDinamicoPR->errorInfo();
		$con->rollBack();
		$conLocal->rollBack();
		echo $sqlDinamico;
		var_dump($msjerror);
		echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
		exit();
	}
}

$sqlHL = "select * from huellas";
$prvHL = $con->prepare($sqlHL);
$prvHL->execute();
$prvHL->bindColumn('cve_huella', $cve_huella, PDO::PARAM_INT);
$prvHL->bindColumn('cve_personal', $cve_personal, PDO::PARAM_INT);
$prvHL->bindColumn('desc_huella', $fileData, PDO::PARAM_STR);

$sqlHD = "delete from huellas";
$prvHD = $conLocal->prepare($sqlHD);
$prvHD->execute();

while($prvHL->fetch(\PDO::FETCH_BOUND)){

	$buffer = pg_escape_bytea($fileData);
	$sqlDinamico = 	" INSERT INTO huellas (cve_huella, cve_personal, desc_huella ) values ($cve_huella,$cve_personal,'$buffer')";
	$prDinamicoHL = $conLocal->prepare($sqlDinamico);
	$o = $prDinamicoHL->execute();
	
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

$sqlTN = "select * from turnos";
$prvTN = $con->prepare($sqlTN);
$prvTN->execute();

foreach ($prvTN as $cve_tn => $val_tn){

	$sqlExiste = "SELECT count(*) total FROM turnos WHERE cve_turno = ".vDt($val_tn['cve_turno'])." ";
	$prExiste = $conLocal->prepare($sqlExiste);
	$prExiste->execute();
    $datExiste = $prExiste->fetch(PDO::FETCH_ASSOC);

    if($datExiste['total'] == '0'){
        $sqlDinamico = 	" INSERT INTO turnos( ".
						" cve_turno, desc_turno, tipo_turno, fecha_registro, creado_por, ip_registro,  ".
						" fecha_modificacion, modificado_por, ip_modifico, estatus_registro)  ".
						" VALUES ( ".
						vDt($val_tn['cve_turno']).", ".vDt($val_tn['desc_turno']).", ".vDt($val_tn['tipo_turno']).", ".
						vDt($val_tn['fecha_registro']).", ".vDt($val_tn['creado_por']).", ".vDt($val_tn['ip_registro']).", ".
						vDt($val_tn['fecha_modificacion']).", ".vDt($val_tn['modificado_por']).", ".vDt($val_tn['ip_modifico']).", ".
						vDt($val_tn['estatus_registro']).")";
    } else {
        $sqlDinamico = 	" UPDATE turnos SET 
						desc_turno = ".vDt($val_tn['desc_turno']).", tipo_turno = ".vDt($val_tn['tipo_turno']).", 
						fecha_registro = ".vDt($val_tn['fecha_registro']).", creado_por = ".vDt($val_tn['creado_por']).", 
						ip_registro = ".vDt($val_tn['ip_registro']).", fecha_modificacion = ".vDt($val_tn['fecha_modificacion']).", 
						modificado_por = ".vDt($val_tn['modificado_por']).", ip_modifico = ".vDt($val_tn['ip_modifico']).", 
						estatus_registro = ".vDt($val_tn['estatus_registro'])." WHERE cve_turno = ".vDt($val_tn['cve_turno'])." ";
    }
	$prDinamicoTN = $conLocal->prepare($sqlDinamico);
	$prDinamicoTN->execute();
}

$sqlDL = "select * from dias_laborables";
$prvDL = $con->prepare($sqlDL);
$prvDL->execute();

foreach ($prvDL as $cve_dl => $val_dl){
	$sqlExiste = "SELECT count(*) total, horario_entrada, horario_salida FROM dias_laborables WHERE cve_dia_laborable = ".vDt($val_dl['cve_dia_laborable'])." GROUP BY horario_entrada, horario_salida ";
	$prExiste = $conLocal->prepare($sqlExiste);
	$prExiste->execute();
    $datExiste = $prExiste->fetch(PDO::FETCH_ASSOC);

    if($datExiste['total'] == '0'){
        $sqlDinamico = 	" INSERT INTO dias_laborables(
						cve_dia_laborable, desc_dia_laborable, cve_turno, fecha_registro, creado_por, 
						ip_registro, fecha_modificacion, modificado_por, ip_modifico, estatus_registro, 
						dia_labor, horario_entrada, horario_salida, tolerancia_entrada, inicio_descanso, 
						fin_descanso, tiempo_descanso) VALUES (".
						vDt($val_dl['cve_dia_laborable']).", ".vDt($val_dl['desc_dia_laborable']).", ".vDt($val_dl['cve_turno']).", ".vDt($val_dl['fecha_registro']).", ".vDt($val_dl['creado_por']).",".
						vDt($val_dl['ip_registro']).", ".vDt($val_dl['fecha_modificacion']).", ".vDt($val_dl['modificado_por']).", ".vDt($val_dl['ip_modifico']).", ".vDt($val_dl['estatus_registro']).", ".
						vDt($val_dl['dia_labor']).", ".vDt($val_dl['horario_entrada']).", ".vDt($val_dl['horario_salida']).", ".vDt($val_dl['tolerancia_entrada']).", ".vDt($val_dl['inicio_descanso']).", ".
						vDt($val_dl['fin_descanso']).", ".vDt($val_dl['tiempo_descanso']).")";
		$prDinamicoDL = $conLocal->prepare($sqlDinamico);
		$prDinamicoDL->execute();
    } else if($datExiste['horario_entrada'] != $val_dl['horario_entrada'] || $datExiste['horario_salida'] != $val_dl['horario_salida'] ){
        $sqlDinamico = 	" update dias_laborables set ".
						" horario_entrada = ".vDt($val_dl['horario_entrada']).", ". 
						" horario_salida = ".vDt($val_dl['horario_salida'])." ".
						" where cve_dia_laborable = ".vDt($val_dl['cve_dia_laborable'])." ";
		$prDinamicoDL = $conLocal->prepare($sqlDinamico);
		$prDinamicoDL->execute();
	}
}

$sqlPT = "select * from personal_turnos";
$prvPT = $con->prepare($sqlPT);
$prvPT->execute();

foreach ($prvPT as $cve_pt => $val_pt){

	$sqlExistePT = "SELECT count(*) total FROM personal_turnos WHERE cve_personal_turno = ".vDt($val_pt['cve_personal_turno'])." ";
	$prExistePT = $conLocal->prepare($sqlExistePT);
	$x = $prExistePT->execute();
    $datExistePT = $prExistePT->fetch(PDO::FETCH_ASSOC);

	if ( !$x ) {
		$msjerror	= $prExistePT->errorInfo();
		$con->rollBack();
		$conLocal->rollBack();
		echo $sqlExistePT;
		var_dump($msjerror);
		echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
		exit();
	}

    if($datExistePT['total'] == '0'){
        $sqlDinamico = 	" INSERT INTO personal_turnos ".
						" (cve_personal_turno, cve_turno, cve_personal, fecha_registro, creado_por, ip_registro, fecha_modificacion, ".
						" modificado_por, ip_modifico, estatus_registro, estatus_turno, tiene_turno_temporal) ".
						" VALUES (".vDt($val_pt['cve_personal_turno']).", ".vDt($val_pt['cve_turno']).", ".vDt($val_pt['cve_personal']).", ".
						vDt($val_pt['fecha_registro']).", ".vDt($val_pt['creado_por']).", ".vDt($val_pt['ip_registro']).", ".vDt($val_pt['fecha_modificacion']).", ".
						vDt($val_pt['modificado_por']).", ".vDt($val_pt['ip_modifico']).", ".vDt($val_pt['estatus_registro']).", ".vDt($val_pt['estatus_turno']).", ".
						vDt($val_pt['tiene_turno_temporal']).") ";
    } else {
        $sqlDinamico = " UPDATE personal_turnos set ".
						" estatus_turno = '".$val_pt['estatus_turno']."' ".
						" WHERE cve_personal_turno = ".$val_pt['cve_personal_turno'];
    }
	$prDinamicoPT = $conLocal->prepare($sqlDinamico);
	$y = $prDinamicoPT->execute();

	if ( !$y ) {
		$msjerror	= $prDinamicoPT->errorInfo();
		$con->rollBack();
		$conLocal->rollBack();
		echo $sqlDinamico;
		var_dump($msjerror);
		echo "El proceso no se realiz&oacute; Reportar con su enlace administrativo. (sincronizado)";
		exit();
	}
}

$sqlNT = "select * from notificaciones";
$prvNT = $con->prepare($sqlNT);
$prvNT->execute();

$sqlND = "delete from notificaciones";
$prvND = $conLocal->prepare($sqlND);
$prvND->execute();

foreach ($prvNT as $cve_nt => $val_nt){
    $sqlDinamico = 	" INSERT INTO notificaciones ".
					" (cve_notificacion, desc_notificacion, mostrar_notificacion, fecha_registro, creado_por,  ".
					" ip_registro, fecha_modificacion, modificado_por, ip_modifico, estatus_registro)  ".
					" VALUES (".vDt($val_nt['cve_notificacion']).", ".vDt($val_nt['desc_notificacion']).", ".vDt($val_nt['mostrar_notificacion']).", ".
								vDt($val_nt['fecha_registro']).", ".vDt($val_nt['creado_por']).", ".vDt($val_nt['ip_registro']).", ".
								vDt($val_nt['fecha_modificacion']).", ".vDt($val_nt['modificado_por']).", ".vDt($val_nt['ip_modifico']).", ".
								vDt($val_nt['estatus_registro']).") ";
	$prDinamicoNT = $conLocal->prepare($sqlDinamico);
	$prDinamicoNT->execute();
}

$sqlDF = "select * from calendario_festivos";
$prvDF = $con->prepare($sqlDF);
$prvDF->execute();

foreach ($prvDF as $cve_df => $val_df){
	$sqlExisteDF = "SELECT count(*) total FROM calendario_festivos WHERE cve_calendario = ".vDt($val_df['cve_calendario'])." ";
	$prExisteDF = $conLocal->prepare($sqlExisteDF);
	$prExisteDF->execute();
    $datExisteDF = $prExisteDF->fetch(PDO::FETCH_ASSOC);

    if($datExisteDF['total'] == '0'){
		$sqlDinamico = 	" INSERT INTO calendario_festivos(
						cve_calendario, anio, nota_festivo, fecha_registro, creado_por, 
						ip_registro, fecha_modificacion, modificado_por, ip_modifico, 
						estatus_registro, fecha_festivo_inicio, fecha_festivo_fin, fecha_festivo_fin_real) VALUES (".
						vDt($val_df['cve_calendario']).", ".vDt($val_df['anio']).", ".vDt($val_df['nota_festivo']).", ".vDt($val_df['fecha_registro']).", ".vDt($val_df['creado_por']).", ".
						vDt($val_df['ip_registro']).", ".vDt($val_df['fecha_modificacion']).", ".vDt($val_df['modificado_por']).", ".vDt($val_df['ip_modifico']).", ".
						vDt($val_df['estatus_registro']).", ".vDt($val_df['fecha_festivo_inicio']).", ".vDt($val_df['fecha_festivo_fin']).", ".vDt($val_df['fecha_festivo_fin_real']).") ";
		$prDinamicoDF = $conLocal->prepare($sqlDinamico);
		$prDinamicoDF->execute();
	}
}

$sqlVC = "select * from vacaciones";
$prvVC = $con->prepare($sqlVC);
$prvVC->execute();

foreach ($prvVC as $cve_vc => $val_vc){
	$sqlExisteDF = "SELECT count(*) total FROM vacaciones WHERE cve_vacacion = ".vDt($val_vc['cve_vacacion'])." ";
	$prExisteDF = $conLocal->prepare($sqlExisteDF);
	$prExisteDF->execute();
    $datExisteDF = $prExisteDF->fetch(PDO::FETCH_ASSOC);

    if($datExisteDF['total'] == '0'){
		$sqlDinamico = 	" INSERT INTO vacaciones
							(cve_vacacion, estatus_vacaciones, fecha_inicio, fecha_final, cve_personal, fecha_registro, 
							 creado_por, ip_registro, fecha_modificacion, modificado_por, ip_modifico, estatus_registro) 
						  VALUES ( ".vDt($val_vc['cve_vacacion']).", ".vDt($val_vc['estatus_vacaciones']).", ".vDt($val_vc['fecha_inicio']).", ".vDt($val_vc['fecha_final']).", ".vDt($val_vc['cve_personal']).", ".vDt($val_vc['fecha_registro']).", 
							 ".vDt($val_vc['creado_por']).", ".vDt($val_vc['ip_registro']).", ".vDt($val_vc['fecha_modificacion']).", ".vDt($val_vc['modificado_por']).", ".vDt($val_vc['ip_modifico']).", ".vDt($val_vc['estatus_registro']).")";
		$prDinamicoDF = $conLocal->prepare($sqlDinamico);
		$prDinamicoDF->execute();
	}
}

$sqlCodigo = "select * from origenes";
$prvCodigo = $conLocal->prepare($sqlCodigo);
$prvCodigo->execute();
$datCodigo = $prvCodigo->fetch(PDO::FETCH_ASSOC);
notifica_sincronizacion($con,$datCodigo['origen']);
/*
$sqFinSin = "UPDATE ejecutar_actualizacion set fecha_peticion = now(), ejecutar_actualizacion = true ";
$prFinSin = $conLocal->prepare($sqFinSin);
$prFinSin->execute();
*/

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
							fecha_actualizacion_general = now() 
							where nombre_origen = '".$origen."' ";
		$prAc = $con->prepare($actualizaOrigen);
		$prAc->execute();
	} else {
		$insertaOrigen = "	insert into check_activos 
							(nombre_origen,fecha_actualizacion_general) 
							values ('".$origen."',now())";
		$prIn = $con->prepare($insertaOrigen);
		$prIn->execute();
	}
}

$con->commit();
$conLocal->commit();

?>



