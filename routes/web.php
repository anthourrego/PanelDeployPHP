<?php

use App\Http\Controllers\PanelController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');
    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.-]+\z/D'],
            'password' => ['required', 'string'],
        ]);
        $credentials['username'] = strtolower($credentials['username']);
        if (! Auth::attempt($credentials + ['is_active' => true])) {
            throw ValidationException::withMessages(['username' => 'Credenciales incorrectas.']);
        }
        $request->session()->regenerate();
        $request->session()->put(EnsureActiveUser::SESSION_VERSION_KEY, $request->user()->session_version);

        return redirect()->intended('/');
    })->middleware('throttle:5,1');
});

Route::middleware(['auth', EnsureActiveUser::class])->group(function () {
    Route::get('/', [PanelController::class, 'index'])->defaults('section', 'summary')->name('panel');
    Route::patch('/perfil', [PanelController::class, 'updateProfile'])->name('profile.update');
    Route::patch('/perfil/contrasena', [PanelController::class, 'updatePassword'])
        ->middleware('throttle:5,1,profile-password:')
        ->name('profile.password.update');
    Route::get('/programaciones', [PanelController::class, 'index'])->defaults('section', 'deployments')->name('panel.deployments');
    Route::get('/proyectos', [PanelController::class, 'index'])->defaults('section', 'projects')->name('panel.projects');
    Route::get('/servidores', [PanelController::class, 'index'])->defaults('section', 'servers')->name('panel.servers');
    Route::get('/descargas', [PanelController::class, 'index'])->defaults('section', 'downloads')->name('panel.downloads');
    Route::middleware('can:manage-users')->group(function () {
        Route::get('/usuarios', [PanelController::class, 'index'])->defaults('section', 'users')->name('panel.users');
        Route::post('/usuarios', [PanelController::class, 'createUser'])->name('users.store');
        Route::patch('/usuarios/{user}', [PanelController::class, 'updateUser'])->name('users.update');
        Route::patch('/usuarios/{user}/estado', [PanelController::class, 'toggleUser'])->name('users.active');
    });
    Route::get('/servidores/descargas/windows/{package}', [PanelController::class, 'downloadWindowsAgent'])->name('servers.agent.download');
    Route::post('/projects', [PanelController::class, 'project'])->name('projects.store');
    Route::patch('/projects/{project}', [PanelController::class, 'updateProject'])->name('projects.update');
    Route::post('/projects/{project}/ssh', [PanelController::class, 'sshRepository'])->name('projects.ssh');
    Route::get('/projects/{project}/references', [PanelController::class, 'projectReferences'])
        ->middleware('throttle:20,1,project-references:')
        ->name('projects.references');
    Route::post('/projects/tags/sync-all', [PanelController::class, 'syncAllProjectTags'])
        ->middleware('throttle:2,1,project-tags-sync-all:')
        ->name('projects.tags.sync-all');
    Route::post('/projects/{project}/tags/sync', [PanelController::class, 'syncProjectTags'])->middleware('throttle:5,1')->name('projects.tags.sync');
    Route::post('/servers', [PanelController::class, 'server'])->name('servers.store');
    Route::post('/assignments', [PanelController::class, 'assignment'])->name('assignments.store');
    Route::post('/deployments', [PanelController::class, 'deployment'])->name('deployments.store');
    Route::post('/targets/{target}/cancel', [PanelController::class, 'cancelTarget'])->name('targets.cancel');
    Route::post('/servers/{server}/active', [PanelController::class, 'toggleServer'])->name('servers.active');
    Route::post('/servers/{server}/token', [PanelController::class, 'agentToken'])->name('servers.token');
    Route::delete('/servers/{server}', [PanelController::class, 'destroyServer'])->name('servers.destroy');
    Route::post('/projects/{project}/active', [PanelController::class, 'toggleProject'])->name('projects.active');
    Route::delete('/projects/{project}', [PanelController::class, 'destroyProject'])->name('projects.destroy');
    Route::patch('/projects/{project}/restore', [PanelController::class, 'restoreProject'])->withTrashed()->name('projects.restore');
    Route::delete('/projects/{project}/servers/{server}', [PanelController::class, 'removeAssignment'])->name('assignments.destroy');
    Route::post('/deployments/{deployment}/cancel', [PanelController::class, 'cancelDeployment'])->name('deployments.cancel');
    Route::delete('/deployments/{deployment}', [PanelController::class, 'destroyDeployment'])->name('deployments.destroy');
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    });
});
