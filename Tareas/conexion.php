<?php
Class Conexion extends PDO
{    
    private $dbname      = 'checkdqm'; 
    private $host        = 'sysdqm.com'; 
    private $usuario     = 'sysdqm';
    private $contrasenia = 't1cDQM#3';
    private $puerto      = 5432;
    private $dbh;
    private $dbname_local      = 'checkdqm';
    private $host_local        = 'localhost'; // 176.31.224.113             localhost
    private $usuario_local     = 'sysdqm';    // sysdqm_admin                 sysdqm
    private $contrasenia_local = 't3mp0r4l';  //'1234';
    private $puerto_local      = 5432;
    private $dbl;
    private $dbname_vil      = 'checkdqm_vil';
    private $host_vil        = 'localhost'; // 176.31.224.113             localhost
    private $usuario_vil     = 'sysdqm';    // sysdqm_admin                 sysdqm
    private $contrasenia_vil = 'temporal';  //'1234';
    private $puerto_vil      = 5432;
    private $dbv;
    
    public function __construct() {
        try{
            $this->dbh = parent::__construct("pgsql:host=$this->host;port=$this->puerto;dbname=$this->dbname;user=$this->usuario;password=$this->contrasenia");
            $this->dbl = parent::__construct("pgsql:host=$this->host_local;port=$this->puerto_local;dbname=$this->dbname_local;user=$this->usuario_local;password=$this->contrasenia_local");
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
    
    public function conectar_local(){
        try {
            $dbl = new PDO("pgsql:host=$this->host_local;
                            port=$this->puerto_local;
                            dbname=$this->dbname_local;
                            user=$this->usuario_local;
                            password=$this->contrasenia_local");
            
            return $dbl;
        } catch(PDOException $ex) {
            echo $ex->getMessage();
        }
    }

    public function conectar_vil(){
        try {
            $dbv = new PDO("pgsql:host=$this->host_vil;
                            port=$this->puerto_vil;
                            dbname=$this->dbname_vil;
                            user=$this->usuario_vil;
                            password=$this->contrasenia_vil");
            
            return $dbv;
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
