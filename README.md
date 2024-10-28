# CheckTools
--- Actualizacion Checkdqm 2.5 ---

1.- Ejecutar el siguiente script en la BD del checkdqm mediante la herramienta pgAdmin:

CREATE TABLE vacaciones_solicitudes (
  cve_vacacion_solicitud numeric(10,0) NOT NULL,
  folio_solicitud varchar(20),
  estatus_solicitud varchar(40),
  cve_personal numeric(10,0) NOT NULL,
  CONSTRAINT "PK109" PRIMARY KEY ("cve_vacacion_solicitud")
);

2.- Remplazar el nombre del archivo ejecutable "jar" en el equipo destino por el nombre de "Huellas_BCK.jar" en caso de que tenga ya instalado previamente el Checkdqm

3.- Pegar los archivos contenidos en la carpeta https://github.com/DiagnostiQM/CheckTools/tree/main/APP, en la carpeta destino del equipo donde se ejecuta el anterior Checkdqm

4.- Eso serian todo los paso para actualizar el Checkdqm.

5.- Ahora ya es posible modificar la zona horaria del check cuando se desactualiza la hora.

6.- Presionar la combinacion de tecla Ctrl + Q

7.- Mostrara una nueva ventana donde se puede indicar la Zona horario que tiene actualmente configurada, elegir la Zona horaria que cumpla con la hora adecuada.

8.- Se cerrara el Checkdqm, ejecutar de nuevo para visualizar el cambio de horario.

--- Herramientas para check 2.0 ---

Los archivos y aplicaciones están probados para nuevas instalaciones, para la instalación de mejoras o actualizaciones se subirá en un release y dentro del release se explicara como se instala esa nueva actualización.

# Solución para actualización de tablas del personal, a petición del usuario desde la página web.

1.- Ejecutar el siguiente script en la BD del checkdqm local. Sí al ejecutar el script de indica que la tabla ya existe ignorar la advertencia.

create table ejecutar_actualizacion (
fecha_peticion timestamp(6),
ejecutar_actualizacion boolean DEFAULT false
);

2.- Instalar la herramienta DQM-Notificaciones release 1.9.0 que se encuentra en la ruta: https://github.com/DiagnostiQM/DesktopDQM.

3.- Pegar la siguiente carpeta dentro de la ruta C:/xampp/htdocs/
	Carpeta checkdqm
 
4.- Pegar los siguientes archivos en la ruta C:/Tareas
	ctrl_sincroniza_tablas_usuario.php
	sincroniza_tablas_usuario.bat

5.- Crear una tarea programa que apunte al archivo sincroniza_tablas_usuario.bat la cual se debe ejecutar cada 5 minutos.

NOTAS: El programa DQM-Notificaciones debe permanecer abierto de lo contrario no podrá tener activos el servicio de sincronización a petición, crear tarea que abra la aplicación al iniciar windows.
