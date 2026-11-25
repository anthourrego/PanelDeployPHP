<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalación completada | Panel de despliegues</title>
    @include('installer-styles')
</head>
<body>
<main>
    <section class="card">
        <div class="brand"><img src="{{ asset('img/prosof-logo.webp') }}" alt="Prosof"></div>
        <div class="success-icon" aria-hidden="true">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg>
        </div>
        <h1 class="heading">Instalación completada</h1>
        <p class="intro" role="status">La base y el primer administrador están configurados. El asistente ha quedado deshabilitado.</p>
        <div class="success-action"><a class="button" href="{{ route('login') }}">Ir al inicio de sesión</a></div>
    </section>
</main>
</body>
</html>
