@extends('layout')
@section('content')
<section style="max-width:420px;margin:2rem auto">
    <h1>Ingresar</h1>
    <form method="post" action="/login">
        @csrf
        <label for="email">Correo</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
        <label for="password">Contraseña</label><input id="password" name="password" type="password" required autocomplete="current-password">
        <button>Ingresar</button>
    </form>
</section>
@endsection
