# Panel de despliegues

Panel Laravel y agente PowerShell para programar un commit de Gitea en repositorios Git existentes de servidores Windows. **No está validado en Windows ni en la infraestructura real; no activar en producción sin pruebas y revisión operativa.** Gitea puede permanecer en HTTP si se usa SSH para consultar y descargar el repositorio. La conexión de los agentes al panel sigue requiriendo HTTPS.

## Panel

Requiere PHP 8.3 o superior, Composer y SQLite o una base compatible con Laravel. Para desarrollo local: `composer install`, copiar `.env.example` a `.env` si falta, ejecutar `php artisan key:generate` una sola vez, `php artisan migrate` y `php artisan panel:create-user`. `php artisan serve` sirve únicamente para desarrollo. Las programaciones existentes pueden ejecutarse en cuanto un agente configurado se conecte.

En producción configure HTTPS con certificado confiable para el panel, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS y `SESSION_SECURE_COOKIE=true`. Bloquee HTTP hacia el panel en la red: rechazar una petición HTTP en Laravel no impide que un token enviado por error haya viajado sin cifrar. Si HTTPS termina en un proxy, configure explícitamente solo las direcciones de proxies confiables en Laravel y bloquee el acceso directo; de lo contrario el panel rechazará las peticiones del agente. Proteja `.env`, la base, los logs y sus copias. No regenere `APP_KEY` en una instalación existente porque cifra los tokens Gitea.

Para SSH, instale Git y OpenSSH en el servidor central y configure una clave de **solo lectura** para la cuenta que ejecuta PHP. Confirme la huella de la clave de host de Gitea por un canal independiente y registre la clave verificada en `known_hosts` de esa cuenta; no acepte una huella sin verificar. La autenticación debe funcionar sin interacción bajo dicha cuenta. El panel exige `BatchMode=yes` y `StrictHostKeyChecking=yes` y no almacena la clave privada. Compruebe la conexión con `git ls-remote` desde esa cuenta antes de programar. Si se usa la alternativa de API HTTPS, tanto la consulta como el token requieren HTTPS confiable; nunca se envía un token por HTTP.

Registre proyecto, servidor y ruta absoluta del repositorio en ese servidor. Para SSH indique la URL exacta de clonación, como `git@servidor:Propietario/Repositorio.git` o `ssh://git@servidor:2222/Propietario/Repositorio.git`. Indique las rutas relativas protegidas y una rama, etiqueta o SHA de Gitea: el panel consulta por SSH, comprueba el commit y guarda su SHA y una copia de las rutas asignadas. Si una rama y una etiqueta comparten nombre, use `refs/heads/nombre` o `refs/tags/nombre`. Para consultar un SHA directamente, el servidor Git debe permitir obtener ese objeto. La hora se ingresa con zona IANA y se muestra en UTC. El panel no permite duplicar un mismo commit pendiente o activo para el mismo proyecto y servidor.

La URL del repositorio queda fijada al programar. Cambiar a SSH un proyecto existente solo afecta nuevas programaciones. Cancele los trabajos pendientes anteriores desde el panel y vuelva a programarlos si necesitan usar la nueva URL; los trabajos activos no se pueden cancelar desde la interfaz.

## Agente Windows

Requiere Windows PowerShell 5.1 o compatible, Git, OpenSSH y acceso HTTPS al panel. Estas dependencias y las rutas deben verificarse en cada servidor. El repositorio debe tener un único `origin` exactamente igual a la URL SSH configurada en el proyecto. Bajo la cuenta que ejecutará la tarea, configure la clave SSH existente con acceso mínimo de lectura y registre en `known_hosts` la clave de host de Gitea, comprobando previamente su huella por un canal independiente. La autenticación debe funcionar sin interacción; el agente exige verificación estricta de la clave de host y nunca copia ni recibe la clave privada desde el panel. También admite repositorios HTTPS con certificado confiable y credenciales Git de solo lectura; no acepta HTTP ni contraseñas en la URL.

1. Cree en el panel el servidor y sus rutas. En el host del panel, ejecute `php artisan panel:agent-token ID`, sustituyendo `ID` por el identificador que muestra el panel. El token se muestra una sola vez. Rotarlo revoca el anterior y no se permite con trabajos activos. Transfiéralo por un canal seguro; no lo incluya en el código ni en mensajes.
2. Copie `agent/PanelDeployAgent.ps1` al servidor Windows. Cree una carpeta privada para el estado y un archivo de configuración protegido con ACL NTFS. Ejemplo de contenido, sustituyendo los marcadores sin compartir el token:

   ```json
   {
     "PanelUrl": "https://panel.ejemplo.local",
     "Token": "ID.TOKEN_GENERADO",
     "StateDirectory": "C:\\ProgramData\\PanelDeploy\\estado"
   }
   ```

   La carpeta de estado debe existir y ser distinta por configuración. Solo la cuenta de servicio y administradores autorizados deben poder leer el token o modificar el script, la configuración y el estado. La cuenta necesita escribir en la carpeta de estado y en el repositorio, pero no requiere privilegios administrativos para ejecutarse.
3. Configure una tarea periódica con esa cuenta y la política de ejecución aprobada por su organización:

   ```text
   powershell.exe -NoProfile -NonInteractive -File "C:\PanelDeploy\PanelDeployAgent.ps1" -ConfigPath "C:\ProgramData\PanelDeploy\config.json"
   ```

   El programador debe impedir instancias simultáneas; el agente también utiliza un mutex local. No desactive la validación TLS. Configure y pruebe primero en una copia no productiva.

### Instalación asistida en Windows

Para una instalación nueva, `agent/Install-PanelDeployAgent.ps1` crea `C:\ProgramData\PanelDeploy`, restringe su ACL a la cuenta indicada, administradores y SYSTEM, y registra la tarea `PanelDeployAgent` cada cinco minutos **deshabilitada**. No sobrescribe una instalación o tarea existente. Requiere PowerShell de Windows ejecutado como administrador, el módulo ScheduledTasks, y una cuenta con contraseña y permiso para iniciar sesión como tarea por lotes. La cuenta debe tener los permisos mínimos necesarios sobre el repositorio y acceso SSH sin interacción. La política de ejecución de PowerShell debe permitir ambos scripts; el instalador no la modifica.

1. Instale en Windows una CA de confianza para el certificado HTTPS del panel, previa verificación de su huella mediante un canal independiente. Para la CA temporal del Mac, copie **solo** `~/.config/panel-deploy/tls/ca.crt`, nunca las claves privadas. Obtenga la huella SHA-256 en el Mac con `openssl x509 -in ~/.config/panel-deploy/tls/ca.crt -noout -fingerprint -sha256`. Para calcular la huella del certificado PEM en Windows y compararla, use:

   ```powershell
   $pem = Get-Content -LiteralPath 'C:\Ruta\ca.crt' -Raw
   $der = [Convert]::FromBase64String(($pem -replace '-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s', ''))
   $sha256 = [Security.Cryptography.SHA256]::Create()
   try { [BitConverter]::ToString($sha256.ComputeHash($der)) } finally { $sha256.Dispose() }
   ```

   Instale la CA en `Cert:\LocalMachine\Root` solo si confía en ella y está autorizado: `Import-Certificate -FilePath C:\Ruta\ca.crt -CertStoreLocation Cert:\LocalMachine\Root`. Confiar en una CA autoriza los certificados que esta firme.
2. Desde Windows compruebe `Test-NetConnection 10.1.0.28 -Port 8443` y visite `https://10.1.0.28:8443/login` sin advertencias. Ajuste la URL si cambia la configuración del panel y use un certificado válido para ese nombre o IP.
3. Genere el token del servidor con el comando del paso anterior; transfiéralo por un canal seguro y no lo pegue en comandos, chats ni archivos temporales. Copie ambos scripts de `agent/` a una carpeta segura en Windows.
4. Abra PowerShell **como administrador** en esa carpeta y ejecute:

   ```powershell
   .\Install-PanelDeployAgent.ps1 -PanelUrl 'https://10.1.0.28:8443'
   ```

   Indique la cuenta Windows que ejecutará la tarea y su contraseña cuando se soliciten; luego introduzca el token en la lectura oculta. La contraseña se entrega al Programador de tareas y el token se guarda en el JSON protegido. Si falla la instalación, revise el error y la carpeta o tarea parcial antes de reintentar; el instalador no elimina ni sobrescribe recursos existentes.
5. Inicie sesión con **esa misma cuenta** y compruebe que Git, OpenSSH y el acceso al panel funcionan. Verifique la huella de Gitea por un canal independiente antes de añadirla al `known_hosts` de esa cuenta. En PowerShell, compruebe el acceso sin interacción:

   ```powershell
   $env:GIT_TERMINAL_PROMPT = '0'
   $env:GIT_SSH_COMMAND = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes'
   git ls-remote ssh://git@192.168.0.214:2225/Prosof_SAS/ProveedoresFive.git
   ```

   Compruebe que el `origin` de la copia de prueba coincide exactamente con la URL del panel.
6. Revise las rutas protegidas y las programaciones pendientes: al habilitar la tarea, cualquier trabajo vencido puede ejecutarse. Después de validar en un repositorio no productivo, habilite desde PowerShell como administrador con `Enable-ScheduledTask -TaskName PanelDeployAgent`. Consulte `Get-ScheduledTaskInfo -TaskName PanelDeployAgent` y los resultados en el panel. Si necesita detener nuevas ejecuciones, use `Disable-ScheduledTask -TaskName PanelDeployAgent`; esto no interrumpe una ejecución ya activa.

## Seguridad, límites y recuperación

Un servidor apagado conserva sus trabajos pendientes. Al conectarse, el agente reclama el más antiguo vencido; la base de datos mantiene un único trabajo activo por servidor. No hay reintento automático de un trabajo reclamado cuyo resultado se desconozca. El agente guarda localmente el resultado antes de enviarlo y reintenta su entrega; el panel acepta reportes repetidos idénticos.

Antes de cambiar de commit, el agente verifica `origin`, el repositorio, las operaciones Git en curso, los árboles, los cambios y archivos locales, incluidos ignorados, y los puntos de reanálisis de las rutas afectadas. Rechaza enlaces simbólicos, submódulos y archivos ocultos al estado de Git con `assume-unchanged` o `skip-worktree`. **Bloquea el despliegue si el commit cambia una ruta protegida**, o si encuentra cambios, archivos sin seguimiento o ignorados fuera de las rutas protegidas. Es una política deliberadamente conservadora: puede requerir limpiar o revisar archivos que no serían sobrescritos. El cambio deja el repositorio en `HEAD` separado, sin mover ramas. No use otros procesos para modificar el repositorio mientras se despliega; el mutex solo coordina instancias de este agente.

Si la conexión se pierde al reclamar, el proceso se interrumpe, o el cambio Git puede haber quedado incompleto, el trabajo permanece activo o incierto y bloquea los siguientes. Confirme primero en Windows que el agente está detenido y revise el estado del repositorio y cualquier resultado local. Solo después, ejecute `php artisan panel:resolve-stuck ID_TRABAJO`: marca ese trabajo como fallido, registra la intervención y desbloquea el servidor; no lo reejecuta. Una entrega pendiente en el archivo de estado debe resolverse antes de volver a activar la tarea. Los estados y errores se muestran por servidor en el panel.

No se ha comprobado el acceso real a Gitea, el comportamiento de Windows PowerShell 5.1, permisos NTFS, cuentas del Programador de tareas, certificados ni los repositorios existentes. Las pruebas locales de Git y PowerShell no sustituyen una prueba integral en Windows.
