<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de despliegues</title>
    <style>
        body{font:16px system-ui,sans-serif;color:#17233b;background:#f3f5f9;margin:0} header{background:#16233d;color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center} main{max-width:1100px;margin:auto;padding:1.5rem} h1,h2{margin-top:0} h2{font-size:1.2rem} .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem} section,.notice{background:white;border-radius:8px;padding:1.2rem;margin-bottom:1rem;box-shadow:0 1px 5px #0001} .notice{border-left:4px solid #d88a00} label{display:block;margin:.7rem 0 .25rem;font-weight:600} input,textarea,select{box-sizing:border-box;width:100%;padding:.55rem;border:1px solid #aeb8c9;border-radius:4px;font:inherit} input[type=checkbox]{width:auto} button{background:#1748a5;color:white;border:0;border-radius:4px;padding:.65rem 1rem;margin-top:1rem;cursor:pointer} header button{margin:0;background:#405879} small{color:#59677b} .errors{color:#982020} .success{color:#126834} table{width:100%;border-collapse:collapse} th,td{text-align:left;padding:.6rem;border-bottom:1px solid #ddd;vertical-align:top} code{overflow-wrap:anywhere}
    </style>
</head>
<body>
<header><strong>Panel de despliegues</strong>@auth<form method="post" action="/logout">@csrf<button>Salir</button></form>@endauth</header>
<main>
    @if (session('success'))<div class="notice success">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="notice errors"><strong>Revise los datos:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
</body>
</html>
