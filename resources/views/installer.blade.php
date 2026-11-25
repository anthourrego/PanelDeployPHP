<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalación inicial | Panel de despliegues</title>
    <style>
        body { font: 16px system-ui, sans-serif; background: #f1f5f9; color: #172033; margin: 0; padding: 2rem 1rem; }
        main { max-width: 38rem; margin: auto; padding: 2rem; background: white; border-radius: .75rem; }
        h1 { margin-top: 0; font-size: 1.5rem; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input:not([type=checkbox]), select { box-sizing: border-box; display: block; width: 100%; padding: .65rem; margin-top: .35rem; border: 1px solid #94a3b8; border-radius: .3rem; font: inherit; }
        button { margin-top: 1.5rem; padding: .7rem 1rem; background: #1d4ed8; color: white; border: 0; border-radius: .3rem; font: inherit; cursor: pointer; }
        .error { background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: .3rem; }
        .note { color: #475569; }
    </style>
</head>
<body>
<main>
    <h1>Instalación inicial</h1>
    @if (count($errors))
        <div class="error" role="alert">
            <ul>
                @foreach ($errors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (! $authorized)
        <p>Ingrese @if (! $local) el código generado en el servidor y @endif los datos de una base ya creada, sin tablas ni vistas.</p>
        <form method="post" action="{{ route('installer.connect') }}" autocomplete="off">
            @csrf
            @if (! $local)
                <label>Código temporal <input type="password" name="code" required></label>
            @endif
            <label>Motor de base de datos
                <select name="driver" required>
                    <option value="sqlsrv">SQL Server</option>
                    <option value="mysql">MySQL local</option>
                </select>
            </label>
            <label>Servidor <input name="host" required></label>
            <p class="note">Para MySQL use localhost, 127.0.0.1 o ::1.</p>
            <label>Puerto <input name="port" type="number" min="1" max="65535" placeholder="1433 para SQL Server; 3306 para MySQL" required></label>
            <label>Base de datos <input name="database" required></label>
            <label>Usuario de base de datos <input name="username" required></label>
            <label>Contraseña de base de datos <input name="db_password" type="password"></label>
            <label><input name="trust_certificate" type="checkbox" value="1"> Confiar en un certificado SQL Server no verificado</label>
            <p class="note">Active esta opción solo si su administrador confirma que es necesaria y la conexión está protegida.</p>
            <button type="submit">Verificar y guardar conexión</button>
        </form>
    @else
        <p>Se ejecutarán las migraciones en la base <strong>{{ config('database.connections.'.config('database.default').'.database') }}</strong> del servidor <strong>{{ config('database.connections.'.config('database.default').'.host') }}</strong>. Confirme que es el destino correcto.</p>
        <form method="post" action="{{ route('installer.finish') }}" autocomplete="off">
            @csrf
            <label>Nombre del administrador <input name="name" required></label>
            <label>Nombre de usuario <input name="username" required></label>
            <label>Correo <input name="email" type="email" required></label>
            <label>Contraseña (mínimo 12 caracteres) <input name="password" type="password" minlength="12" required></label>
            <label>Confirmar contraseña <input name="password_confirmation" type="password" minlength="12" required></label>
            <button type="submit">Confirmar e instalar</button>
        </form>
    @endif
</main>
</body>
</html>
