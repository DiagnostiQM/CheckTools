<?php
require_once './dbconexion/conexion.php';
$dbcon = new Conexion;
$conexion = $dbcon->conectar();

$sqlSincroniza = "select count(*) total from ejecutar_actualizacion";
$prSincroniza = $conexion->prepare($sqlSincroniza);
$sin = $prSincroniza->execute();
$datSin = $prSincroniza->fetch(PDO::FETCH_ASSOC);

if($sin){
    $conexion->beginTransaction();
    $sql = "";
    if($datSin['total'] == 0){
        $sql = "INSERT INTO ejecutar_actualizacion values (now(),true)";
    } else {
        $sql = "UPDATE ejecutar_actualizacion set fecha_peticion = now(), ejecutar_actualizacion = true ";
    }
    echo $sql;
    $prProg = $conexion->prepare($sql);
    $prProg->execute();
    $conexion->commit();
}

?>