# Panel de despliegues

Panel Laravel y agente Windows para programar un commit de Gitea en repositorios Git existentes. **No activar en producción sin validar el agente en Windows y en un repositorio de pruebas.** Gitea puede permanecer en HTTP si se usa SSH para consultar y descargar el repositorio. La conexión de los agentes al panel sigue requiriendo HTTPS.

## Panel

El resumen abre en `/`. También puede acceder directamente a `/programaciones`, `/proyectos`, `/servidores` y `/descargas`; al recargar se conserva la sección indicada por la URL.

En **Programaciones** puede filtrar todo el historial por proyecto, servidor, estado y rango de fechas programadas. Las fechas incluyen los días completos en UTC. Al combinar servidor y estado, ambos deben corresponder al mismo trabajo; se siguen mostrando todos los servidores de cada programación encontrada. Los resultados se muestran en páginas de 20 y los filtros se conservan al navegar entre ellas.

La versión del panel aparece en el menú lateral y se define en `config/app.php`, en la clave `version`. Actualícela en cada lanzamiento y, si usa caché de configuración, regenérela al publicar. Es independiente de Git, Gitea y de la versión del agente Windows.

Use el botón de sol o luna en la cabecera del panel o en el inicio de sesión para cambiar entre tema claro y oscuro. La preferencia se guarda en el navegador; el tema inicial es claro.

Requiere PHP 8.3 o superior, Composer, Node.js y SQLite o una base compatible con Laravel. Para una instalación local nueva con SQL Server, siga el asistente de la sección siguiente. Si ya existe `.env`, `composer install` conserva la configuración y no activa el asistente; para la instalación manual, configure el entorno, genere `APP_KEY` solo si aún no existe, ejecute `php artisan migrate` y `php artisan panel:create-user`. El comando solicita nombre, username, correo y contraseña; el primer usuario creado de una instalación nueva queda como administrador. El inicio de sesión usa el username (letras ASCII, números, punto, guion o guion bajo; no distingue mayúsculas); el correo se conserva como dato de contacto. Después de migrar una instalación existente, asigne manualmente un username único en minúsculas en `users.username` a cada cuenta antes de que inicie sesión; la migración deja esos valores vacíos y conserva el acceso de administrador para las cuentas existentes. Para desarrollo manual ejecute `php artisan serve` y `npm run dev` en terminales separadas; ambos comandos sirven únicamente para desarrollo. Para compilar los recursos de producción ejecute `npm run build`. Las programaciones existentes pueden ejecutarse en cuanto un agente configurado se conecte.

### Instalación web inicial con SQL Server

El asistente es exclusivamente para una instalación nueva en una sola instancia; no sirve para actualizar ni reconectar un panel existente. No crea la base SQL Server. Prepare una base de destino sin tablas ni vistas, una cuenta con `VIEW DEFINITION` y permisos para ejecutar las migraciones, y las extensiones PHP `pdo_sqlite` y `pdo_sqlsrv`. Las funciones y procedimientos existentes no se eliminan.

1. Clone el proyecto en una carpeta nueva e instale PHP, Composer, Node.js y npm. Desde una **terminal interactiva**, ejecute `composer install` como la cuenta que ejecutará PHP. Composer instalará y compilará el frontend, creará `.env`, la clave y una base SQLite temporal, y habilitará el asistente. `composer run setup` hace lo mismo. Si ya existe `.env` o hay indicios de una instalación anterior, no se sobrescribe la configuración. En ejecución no interactiva no se inicia la preparación local.
2. Composer muestra un código temporal y mantiene un servidor PHP en primer plano, limitado a `127.0.0.1:8000`. Abra `/instalacion` en ese servidor desde el mismo equipo. El HTTP de este modo es solo para uso local: no lo exponga a la red ni lo use en producción. Mantenga la terminal abierta; Ctrl+C detiene el servidor.
3. Introduzca el código y los datos de SQL Server. La conexión usa cifrado y verifica el certificado por defecto; marque la opción de confiar en un certificado no verificado únicamente si su administrador lo autoriza. El asistente comprueba la conexión y que no existan tablas ni vistas antes de guardar `.env`. En la siguiente pantalla confirme el destino y cree el administrador. No comparta credenciales ni el código.
4. Al finalizar, el asistente queda deshabilitado. Si detuvo el servidor antes de terminar, vuelva a ejecutar `composer install` en una terminal interactiva para obtener otro código. El código vence a los 30 minutos, se consume al validar la conexión y se bloquea después de diez intentos incorrectos. Una vez terminada la instalación, `composer install` no vuelve a habilitar el asistente; para desarrollo local puede arrancar el panel con `php artisan serve --host=127.0.0.1 --port=8000`.

El servidor PHP necesita escribir temporalmente en el directorio de la aplicación para reemplazar `.env` y en `storage/app/private` para el estado; restrinja esos permisos a la cuenta del servicio y retírelos al terminar. En Unix, `.env` queda con permisos `0600`; en Windows, configure ACL restrictivas equivalentes. Si usa un servidor web real en lugar del modo local, prepare una copia nueva de `.env.example` con `APP_DEBUG=false`, una `APP_KEY` generada una sola vez y `DB_CONNECTION=sqlite` apuntando a un archivo SQLite nuevo y vacío. Configure HTTPS con certificado confiable, restrinja la red y habilite el asistente manualmente con `php artisan panel:install-enable` como la cuenta de PHP. No use el servidor PHP de desarrollo en producción. Si hay variables externas o caché de configuración, la instalación automática se detiene para evitar sobrescribir una instalación existente.

Si falla una migración, revise el estado y los permisos antes de reintentar: algunas migraciones pueden haber quedado parcialmente aplicadas. No use `migrate:fresh` ni elimine datos para recuperarla. Si ya se creó un usuario, resuelva el estado manualmente con un administrador.

La sección **Usuarios** solo está disponible para administradores. Al crear una cuenta, marque **Cuenta de administrador** para darle acceso a esta sección; la casilla está desmarcada por defecto. Use **Editar** para cambiar nombre, username, correo o rol. **Desactivar** bloquea el acceso e invalida las sesiones anteriores sin borrar el historial de despliegues. Al **Reactivar**, el usuario debe iniciar sesión otra vez. El último administrador activo no puede desactivarse ni perder su rol.

Al crear o editar una cuenta, el username no admite espacios. El nombre visible sí puede contenerlos.

Desde el menú del usuario en la cabecera, **Editar perfil** permite cambiar el nombre, el username y el correo sin modificar el rol. **Cambiar contraseña** pide la contraseña actual y la confirmación de la nueva; al guardar, las demás sesiones se invalidan.

En producción configure HTTPS con certificado confiable para el panel, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS y `SESSION_SECURE_COOKIE=true`. Bloquee HTTP hacia el panel en la red: rechazar una petición HTTP en Laravel no impide que un token enviado por error haya viajado sin cifrar. Si HTTPS termina en un proxy, configure explícitamente solo las direcciones de proxies confiables en Laravel y bloquee el acceso directo; de lo contrario el panel rechazará las peticiones del agente. Proteja `.env`, la base, los logs y sus copias. No regenere `APP_KEY` en una instalación existente porque cifra los tokens Gitea.

Para SSH, instale Git y OpenSSH en el servidor central y configure una clave de **solo lectura** para la cuenta que ejecuta PHP. Confirme la huella de la clave de host de Gitea por un canal independiente y registre la clave verificada en `known_hosts` de esa cuenta; no acepte una huella sin verificar. La autenticación debe funcionar sin interacción bajo dicha cuenta. El panel exige `BatchMode=yes` y `StrictHostKeyChecking=yes` y no almacena la clave privada. Compruebe la conexión con `git ls-remote` desde esa cuenta antes de programar. Si se usa la alternativa de API HTTPS, tanto la consulta como el token requieren HTTPS confiable; nunca se envía un token por HTTP.

Registre proyecto, servidor y ruta absoluta del repositorio en ese servidor. Para SSH indique la URL exacta de clonación, como `git@servidor:Propietario/Repositorio.git` o `ssh://git@servidor:2222/Propietario/Repositorio.git`. En **Programar despliegue**, seleccione el proyecto y luego una de sus ramas o etiquetas. El selector muestra todas las ramas y hasta 20 etiquetas, ordenadas por versión de mayor a menor. El panel guarda la referencia seleccionada, el commit al que apunta al programar y una copia de las rutas asignadas. Así se despliega esa misma versión aunque la rama cambie antes de la ejecución. No se admiten SHA manuales para programar. La hora se ingresa en la zona configurada y se muestra en UTC. El panel no permite duplicar un mismo commit pendiente o activo para el mismo proyecto y servidor.

La URL del repositorio queda fijada al programar. Cambiar a SSH un proyecto existente solo afecta nuevas programaciones. Cancele los trabajos pendientes anteriores desde el panel y vuelva a programarlos si necesitan usar la nueva URL; los trabajos activos no se pueden cancelar desde la interfaz.

La programación utiliza un calendario en español y un campo de hora de 12 horas (AM/PM) con React Aria, compatibles con interacción táctil y teclado. Configure `DEPLOYMENT_TIMEZONE` en `.env` con una zona IANA válida; por defecto es `America/Bogota`. El formulario muestra esta zona y no utiliza la del dispositivo ni la del servidor de destino. Las fechas se guardan en UTC; cambiar la zona no modifica las programaciones existentes. Si usa caché de configuración, regenere la caché tras cambiar `.env`. El idioma predeterminado de Laravel es español (`APP_LOCALE=es`).

En **Proyectos → Sincronizar todos** puede consultar la última versión disponible de todos los repositorios registrados, incluidos los desactivados, sin iniciar despliegues. Si una consulta falla, el panel conserva la última versión conocida de ese proyecto y muestra el error en su tarjeta.
Para consultar un solo proyecto, pulse el botón con el icono de actualización junto a **Última versión disponible**.

Debajo de cada ruta asignada, **Última confirmada** muestra la referencia, el commit y la fecha del último despliegue exitoso en ese servidor. Este dato proviene del historial, no de una consulta en vivo: no detecta cambios manuales y se muestra una advertencia si un despliegue posterior dejó el estado incierto.

## Agente Windows

### Ejecutable e instalador

El agente C# se distribuye para Windows x64 como `PanelDeployAgent.exe`. Incluye .NET y no usa PowerShell. Sigue necesitando Git y, para remotos SSH, OpenSSH. Usa la misma API del panel y el mismo formato de `config.json` y del resultado pendiente que el agente anterior.

En **Descargas → Agente para Windows x64 → Guía de instalación** encontrará el paso a paso para instalar o actualizar el agente. La generación de tokens sigue en **Servidores**. Para habilitar las descargas, publique estos archivos en el **servidor del panel**:

| Archivo generado | Destino dentro de PanelDeploy |
|---|---|
| `agent/dist/win-x64/setup/PanelDeploySetup.exe` | `storage/app/private/agent/win-x64/PanelDeploySetup.exe` |
| `agent/dist/win-x64/agent/PanelDeployAgent.exe` | `storage/app/private/agent/win-x64/PanelDeployAgent.exe` |
| `PanelDeployPowerShell.zip` (alternativa para TI) | `storage/app/private/agent/win-x64/PanelDeployPowerShell.zip` |

Empaquete los ejecutables como artefactos del despliegue: no se incluyen en Git y el servidor web no necesita .NET para servirlos. Mantenga esa carpeta fuera de `public`, con permisos de lectura para el servidor web y de escritura solo para quien publica la versión. Las descargas requieren iniciar sesión; si falta un archivo, el panel indica que no se ha publicado. Si quiere mostrar el número de la versión, configure `WINDOWS_AGENT_VERSION` y regenere la caché de configuración si la usa.

Para generar ambos ejecutables desde la raíz del repositorio con el SDK de .NET 10 (ejemplo en PowerShell):

```powershell
dotnet publish agent/PanelDeployAgent -c Release -r win-x64 --self-contained -p:PublishSingleFile=true -o agent/dist/win-x64/agent
$agentExe = (Resolve-Path 'agent/dist/win-x64/agent/PanelDeployAgent.exe').Path
dotnet publish agent/PanelDeploySetup -c Release -r win-x64 --self-contained -p:PublishSingleFile=true "-p:AgentExecutable=$agentExe" -o agent/dist/win-x64/setup
```

1. Prepare Git, la cuenta Windows con permiso para iniciar sesión como tarea por lotes, la confianza HTTPS del panel y el acceso al repositorio bajo esa cuenta. Genere el token del servidor con `php artisan panel:agent-token ID` en el panel y transfiéralo por un canal seguro.
2. Descargue `PanelDeploySetup.exe` desde **Descargas** y ábralo en un Windows de pruebas. El instalador solicita elevación, la URL HTTPS del panel y la cuenta Windows. La contraseña y el token se leen sin mostrarlos.
3. La instalación crea `C:\ProgramData\PanelDeploy` con permisos NTFS restringidos y registra la tarea `PanelDeployAgent` **deshabilitada**, cada cinco minutos. El binario y la configuración son de solo lectura para una cuenta de servicio no administradora; la cuenta puede escribir en `estado`. El instalador no sobrescribe una tarea o carpeta existente.
4. Antes de habilitar la tarea desde el Programador de tareas, revise los trabajos pendientes (habilitarla puede ejecutar los vencidos). Pruebe el agente con la misma cuenta en un servidor y repositorio no productivos. Puede comprobar el resultado desde el panel y en el historial de la tarea.

**Si ya existe una instalación PowerShell:** deshabilite la tarea y compruebe que no haya ejecuciones activas antes de cambiarla. Conserve `config.json`, la carpeta `estado` y sus permisos; coloque el ejecutable en la carpeta protegida. En la acción de la tarea, cambie el programa a `C:\ProgramData\PanelDeploy\PanelDeployAgent.exe` y los argumentos a `--config "C:\ProgramData\PanelDeploy\config.json"`. Revise los resultados pendientes antes de reactivarla. La acción anterior se puede restaurar con la tarea detenida.

Desde macOS o Linux, `dotnet run --project tests/Agent/PanelDeployAgent.Tests.csproj` comprueba el cambio de commit y la protección de archivos con un Git local y SSH simulado. No sustituye una prueba integral del instalador, la tarea y los permisos en Windows.

### Agente anterior de PowerShell (alternativa)

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

#### Instalación anterior con PowerShell

Para publicar la descarga **Alternativa PowerShell**, desde la raíz del proyecto genere el ZIP con los dos scripts en el nivel raíz del archivo (ejemplo en PowerShell):

```powershell
Compress-Archive -LiteralPath 'agent/Install-PanelDeployAgent.ps1','agent/PanelDeployAgent.ps1' -DestinationPath 'PanelDeployPowerShell.zip' -Force
```

No agregue archivos de configuración ni credenciales al ZIP. Descárguelo desde **Descargas** y extraiga ambos scripts juntos antes de instalar.

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
