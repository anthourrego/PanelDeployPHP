@extends('layout')
@section('content')
<div class="notice"><strong>Validación pendiente:</strong> el agente requiere HTTPS y pruebas en Windows antes de usarse en producción. Si el commit cambia una ruta protegida, se bloqueará el despliegue.</div>
<div class="grid">
<section>
    <h2>Registrar proyecto</h2>
    <form method="post" action="{{ route('projects.store') }}">@csrf
        <label>Nombre</label><input name="name" required>
        <label>URL base de Gitea</label><input name="gitea_url" type="url" placeholder="http://192.168.0.214:3000" required>
        <label>Propietario</label><input name="owner" placeholder="Prosof_SAS" required>
        <label>Repositorio</label><input name="repository" placeholder="ProveedoresFive" required>
        <label>URL SSH del repositorio (opcional)</label><input name="ssh_url" placeholder="git@servidor:Propietario/Repositorio.git"><small>Con SSH, el panel consulta versiones usando su propia clave; Gitea no necesita HTTPS.</small>
        <label>Token de Gitea (opcional, solo HTTPS)</label><input name="gitea_token" type="password" autocomplete="off">
        <label>Rutas relativas a preservar, una por línea</label><textarea name="preserved_paths" rows="3" placeholder="application/config/database.php&#10;uploads"></textarea>
        <small>El agente bloqueará commits que cambien rutas protegidas o encuentre cambios locales fuera de ellas.</small><br>
        <button>Registrar proyecto</button>
    </form>
</section>
<section>
    <h2>Registrar servidor</h2>
    <form method="post" action="{{ route('servers.store') }}">@csrf
        <label>Nombre</label><input name="name" required>
        <button>Registrar servidor</button>
    </form>
    <h2 style="margin-top:2rem">Asignar ruta</h2>
    <form method="post" action="{{ route('assignments.store') }}">@csrf
        <label>Proyecto</label><select name="project_id" required><option value="">Seleccione</option>@foreach ($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
        <label>Servidor</label><select name="server_id" required><option value="">Seleccione</option>@foreach ($servers as $server)<option value="{{ $server->id }}">{{ $server->name }}</option>@endforeach</select>
        <label>Ruta absoluta en Windows</label><input name="path" placeholder="C:\wamp64\www\proyecto" required>
        <button>Guardar ruta</button>
    </form>
</section>
<section>
    <h2>Programar</h2>
    <form method="post" action="{{ route('deployments.store') }}">@csrf
        <label>Proyecto</label><select name="project_id" required><option value="">Seleccione</option>@foreach ($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
        <label>Referencia de Gitea (rama, etiqueta o commit)</label><input name="ref" required>
        <small>Se consulta Gitea y se guarda el SHA exacto.</small>
        <label>Fecha y hora</label><input name="scheduled_at" type="datetime-local" required>
        <label>Zona horaria</label><input name="timezone" value="America/Bogota" required><small>Use una zona IANA, por ejemplo America/Bogota.</small>
        <label>Servidores</label>
        @forelse ($servers as $server)<div><label><input type="checkbox" name="servers[]" value="{{ $server->id }}"> {{ $server->name }}</label></div>@empty <small>Registre un servidor primero.</small> @endforelse
        <button>Guardar programación</button>
    </form>
</section>
</div>
<section>
    <h2>Servidores</h2>
    @forelse ($servers as $server)<p>{{ $server->name }} — ID: {{ $server->id }} — última conexión: {{ $server->last_seen_at?->format('Y-m-d H:i:s') ?? 'sin conexión' }} UTC @if ($server->active_target_id) — trabajo activo: {{ $server->active_target_id }} @endif</p>@empty<p>No hay servidores registrados.</p>@endforelse
</section>
<section>
    <h2>Proyectos y rutas</h2>
    @forelse ($projects as $project)
        <p><strong>{{ $project->name }}</strong> — {{ $project->owner }}/{{ $project->repository }}
        @forelse ($project->servers as $server)<br>{{ $server->name }}: <code>{{ $server->pivot->path }}</code>@empty<br><small>Sin servidores asignados.</small>@endforelse</p>
        <form method="post" action="{{ route('projects.ssh', $project) }}">@csrf<label>URL SSH para nuevas programaciones</label><input name="ssh_url" value="{{ $project->ssh_url }}" placeholder="git@servidor:Propietario/Repositorio.git"><button>Guardar conexión SSH</button></form>
    @empty <p>No hay proyectos registrados.</p> @endforelse
</section>
<section>
    <h2>Programaciones</h2>
    <table><thead><tr><th>Proyecto / versión</th><th>Fecha (UTC)</th><th>Servidores</th></tr></thead><tbody>
    @forelse ($deployments as $deployment)
    <tr><td>{{ $deployment->project->name }}<br>{{ $deployment->ref }}<br><code>{{ $deployment->commit }}</code></td><td>{{ $deployment->scheduled_at->format('Y-m-d H:i') }}</td><td>@foreach ($deployment->targets as $target){{ $target->server->name }}: {{ $target->status }}@if ($target->result) — {{ $target->result }}@endif @if ($target->status === 'pending')<form method="post" action="{{ route('targets.cancel', $target) }}">@csrf<button>Cancelar trabajo</button></form>@endif<br>@endforeach</td></tr>
    @empty <tr><td colspan="3">No hay programaciones.</td></tr> @endforelse
    </tbody></table>
</section>
@endsection
