<?php

use App\Models\DeploymentTarget;
use App\Models\Server;
use App\Models\User;
use App\Services\Installer;
use App\Services\InstallerException;
use App\Services\LocalInstallerBootstrap;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('panel:install-local', function (LocalInstallerBootstrap $bootstrap) {
    $this->info('Comprobando la instalación y las dependencias del frontend...');
    try {
        $code = $bootstrap->prepare();
    } catch (InstallerException $exception) {
        $this->error($exception->getMessage());

        return 1;
    } catch (Throwable $exception) {
        Log::error('No se pudo preparar el asistente local.', ['exception' => $exception::class]);
        $this->error('No se pudo preparar el asistente local. Revise los permisos y las dependencias.');

        return 1;
    }

    if ($code === null) {
        $this->info('Se conservó la configuración existente; no se inició el asistente.');

        return 0;
    }

    $this->info('Asistente preparado. Configure Apache para servir la carpeta public y abra /instalacion desde este equipo.');

    return 0;
})->purpose('Preparar el asistente local inicial');

Artisan::command('panel:install-enable', function (Installer $installer) {
    try {
        $code = $installer->enable();
    } catch (InstallerException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->line('Código temporal de instalación: '.$code);
    $this->info('Abra /instalacion mediante HTTPS. El código vence en 30 minutos.');

    return 0;
})->purpose('Habilitar temporalmente la instalación inicial');

Artisan::command('panel:create-user', function () {
    $name = $this->ask('Nombre');
    $username = strtolower($this->ask('Nombre de usuario'));
    $email = $this->ask('Correo');
    $password = $this->secret('Contraseña (mínimo 12 caracteres)');
    $confirmation = $this->secret('Confirme la contraseña');
    $validator = Validator::make(
        compact('name', 'username', 'email', 'password') + ['password_confirmation' => $confirmation],
        [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.-]+\z/D', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ],
    );
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    $isFirstUser = ! User::query()->exists();
    $user = User::create(compact('name', 'username', 'email', 'password'));
    if ($isFirstUser) {
        $user->forceFill(['is_admin' => true])->save();
    }
    $this->info($isFirstUser ? 'Usuario creado como administrador.' : 'Usuario creado.');

    return 0;
})->purpose('Crear un usuario del panel');

Artisan::command('panel:agent-token {server_id}', function () {
    $server = Server::find($this->argument('server_id'));
    if (! $server) {
        $this->error('Servidor no encontrado.');

        return 1;
    }
    if ($server->active_target_id !== null) {
        $this->error('No se puede cambiar el token mientras existe un trabajo en ejecución.');

        return 1;
    }
    if ($server->agent_token_hash && ! $this->confirm('Esto revocará el token anterior. ¿Continuar?', false)) {
        return 1;
    }
    $token = $server->rotateAgentToken();
    if ($token === null) {
        $this->error('El servidor ahora tiene un trabajo activo; no se cambió el token.');

        return 1;
    }
    $this->line('Token (se muestra una sola vez): '.$token);

    return 0;
})->purpose('Generar o rotar el token de un servidor');

Artisan::command('panel:resolve-stuck {target_id}', function () {
    $target = DeploymentTarget::find($this->argument('target_id'));
    if (! $target || ! in_array($target->status, ['running', 'uncertain'], true)) {
        $this->error('El trabajo no existe o no requiere revisión.');

        return 1;
    }
    if (! $this->confirm('¿Confirmó en Windows que el agente está detenido y revisó el estado del repositorio? El trabajo se marcará como fallido.', false)) {
        return 1;
    }
    $resolved = DB::transaction(function () use ($target) {
        $server = Server::whereKey($target->server_id)->lockForUpdate()->firstOrFail();
        $target = DeploymentTarget::whereKey($target->id)->lockForUpdate()->firstOrFail();
        if ($server->active_target_id !== $target->id || ! in_array($target->status, ['running', 'uncertain'], true)) {
            return false;
        }
        $target->status = 'failed';
        $target->result = trim(($target->result ?? '')."\nCerrado manualmente después de revisión.");
        $target->finished_at = now();
        $target->save();
        $server->active_target_id = null;
        $server->save();
        DB::table('deployment_reservations')->where('deployment_target_id', $target->id)->delete();
        Log::warning('Trabajo cerrado manualmente.', ['target_id' => $target->id, 'server_id' => $server->id]);

        return true;
    });
    if (! $resolved) {
        $this->error('El estado cambió; no se realizó ninguna modificación.');

        return 1;
    }
    $this->info('Trabajo cerrado como fallido.');

    return 0;
})->purpose('Cerrar un trabajo bloqueado después de revisión manual');
