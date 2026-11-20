<?php

use App\Http\Controllers\PanelController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');
    Route::post('/login', function (Request $request) {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas.']);
        }
        $request->session()->regenerate();

        return redirect()->intended('/');
    })->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [PanelController::class, 'index'])->defaults('section', 'summary')->name('panel');
    Route::get('/programaciones', [PanelController::class, 'index'])->defaults('section', 'deployments')->name('panel.deployments');
    Route::get('/proyectos', [PanelController::class, 'index'])->defaults('section', 'projects')->name('panel.projects');
    Route::get('/servidores', [PanelController::class, 'index'])->defaults('section', 'servers')->name('panel.servers');
    Route::post('/projects', [PanelController::class, 'project'])->name('projects.store');
    Route::post('/projects/{project}/ssh', [PanelController::class, 'sshRepository'])->name('projects.ssh');
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
