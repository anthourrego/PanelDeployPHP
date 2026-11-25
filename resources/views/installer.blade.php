<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalación inicial | Panel de despliegues</title>
    @include('installer-styles')
</head>
<body>
<main>
    <section class="card">
        <div class="brand"><img src="{{ asset('img/prosof-logo.webp') }}" alt="Prosof"></div>
        <h1 class="heading">Instalación inicial</h1>
        <p class="intro">Configure el panel de despliegues en unos sencillos pasos.</p>

        <ol class="steps" aria-label="Pasos de instalación">
            <li class="step {{ ! $authorized ? 'step-current' : '' }}" @if (! $authorized) aria-current="step" @endif>
                <span class="step-number">1</span> Conexión
            </li>
            <li class="step-divider" aria-hidden="true"></li>
            <li class="step {{ $authorized ? 'step-current' : '' }}" @if ($authorized) aria-current="step" @endif>
                <span class="step-number">2</span> Administrador
            </li>
        </ol>

        @if (count($errors))
            <div class="notice" role="alert">
                <svg aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                <div>
                    <p class="notice-title">Revise los datos</p>
                    <ul>
                        @foreach ($errors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @if (! $authorized)
            @php
                $connectionInput = request()->isMethod('POST')
                    ? array_filter(request()->only(['driver', 'host', 'port', 'database', 'username', 'trust_certificate']), 'is_scalar')
                    : [];
            @endphp
            <p class="intro">Ingrese @if (! $local) el código generado en el servidor y @endif los datos de una base ya creada, sin tablas ni vistas.</p>
            <form class="form" method="post" action="{{ route('installer.connect') }}" autocomplete="off">
                @csrf
                @if (! $local)
                    <label class="field">Código temporal <input type="password" name="code" required></label>
                @endif
                <label class="field">Motor de base de datos
                    <select name="driver" required>
                        <option value="sqlsrv" @selected(($connectionInput['driver'] ?? 'sqlsrv') === 'sqlsrv')>SQL Server</option>
                        <option value="mysql" @selected(($connectionInput['driver'] ?? null) === 'mysql')>MySQL local</option>
                    </select>
                </label>
                <div class="fields">
                    <label class="field">Servidor
                        <input name="host" value="{{ $connectionInput['host'] ?? '' }}" aria-describedby="host-hint" required>
                        <span class="hint" id="host-hint">Para MySQL use localhost, 127.0.0.1 o ::1.</span>
                    </label>
                    <label class="field">Puerto
                        <input name="port" type="number" min="1" max="65535" value="{{ $connectionInput['port'] ?? '' }}" aria-describedby="port-hint" required>
                        <span class="hint" id="port-hint">1433 para SQL Server; 3306 para MySQL.</span>
                    </label>
                </div>
                <label class="field">Base de datos <input name="database" value="{{ $connectionInput['database'] ?? '' }}" required></label>
                <div class="fields">
                    <label class="field">Usuario de base de datos <input name="username" value="{{ $connectionInput['username'] ?? '' }}" required></label>
                    <label class="field">Contraseña de base de datos <input name="db_password" type="password"></label>
                </div>
                <div>
                    <label class="checkbox"><input name="trust_certificate" type="checkbox" value="1" aria-describedby="certificate-hint" @checked((string) ($connectionInput['trust_certificate'] ?? '') === '1')> Confiar en un certificado SQL Server no verificado</label>
                    <p class="hint" id="certificate-hint">Active esta opción solo si su administrador confirma que es necesaria y la conexión está protegida.</p>
                </div>
                <button class="button" type="submit">Verificar y guardar conexión</button>
            </form>
        @else
            <div class="destination">
                <p>Se ejecutarán las migraciones en la base <strong>{{ config('database.connections.'.config('database.default').'.database') }}</strong> del servidor <strong>{{ config('database.connections.'.config('database.default').'.host') }}</strong>.</p>
                <p>Confirme que es el destino correcto y cree la cuenta de administrador.</p>
            </div>
            <form class="form" method="post" action="{{ route('installer.finish') }}" autocomplete="off">
                @csrf
                <div class="fields">
                    <label class="field">Nombre del administrador <input name="name" required></label>
                    <label class="field">Nombre de usuario <input name="username" required></label>
                </div>
                <label class="field">Correo <input name="email" type="email" required></label>
                <div class="fields">
                    <label class="field">Contraseña (mínimo 12 caracteres) <input name="password" type="password" minlength="12" required></label>
                    <label class="field">Confirmar contraseña <input name="password_confirmation" type="password" minlength="12" required></label>
                </div>
                <button class="button" type="submit">Confirmar e instalar</button>
            </form>
        @endif
    </section>
</main>
</body>
</html>
