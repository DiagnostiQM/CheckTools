<?php
Class Conexion extends PDO
{    
    private $dbname      = 'checkdqm_limpio';// 
    private $host        = 'localhost'; // 176.31.224.113             localhost
    private $usuario     = 'sysdqm';    // sysdqm_admin                 sysdqm
    private $contrasenia = 'temporal';  //'1234';
    private $puerto      = 5432;
    private $dbh;
    
    public function __construct() {
        try{
            $this->dbh = parent::__construct("pgsql:host=$this->host;port=$this->puerto;dbname=$this->dbname;user=$this->usuario;password=$this->contrasenia");
        } catch (Exception $exception) {
           echo $exception->getMessage();
        }
    }
   
    public function conectar(){
        try {
            $dbh = new PDO("pgsql:host=$this->host;
                            port=$this->puerto;
                            dbname=$this->dbname;
                            user=$this->usuario;
                            password=$this->contrasenia");
            
            return $dbh;
        } catch(PDOException $ex) {
            echo $ex->getMessage();
        }
    }
    
    public function cerrar(){
        $dbh = NULL;   
        return $dbh;
    }
    
    public function baseUrl(){
        $direccion_actual = getcwd();
        $direccion_origen = dirname(__FILE__);
        $direccion_raiz = dirname($direccion_origen);

        $comparacion =  str_replace($direccion_raiz,'',$direccion_actual);
        $comparacion = str_replace("\\","/",$comparacion);

        $base_url = "";
        $dato = explode('/',$comparacion);

        for($i = 1; $i < count($dato); $i++){
                $base_url .= "../";
        }
        
        return $base_url;
    }
}
?>
