<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de despliegues</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <script>
        try {
            document.documentElement.dataset.theme = localStorage.getItem('panel.theme') === 'dark' ? 'dark' : 'light';
        } catch (error) {
            if (error.name !== 'SecurityError') throw error;
            console.warn('El navegador no permite leer la preferencia del tema.', error);
        }
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    <x-inertia::head />
</head>
<body>
    <x-inertia::app />
</body>
</html>
