<?php

use App\Http\Controllers\PanelController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
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
    Route::get('/', [PanelController::class, 'index'])->name('panel');
    Route::post('/projects', [PanelController::class, 'project'])->name('projects.store');
    Route::post('/projects/{project}/ssh', [PanelController::class, 'sshRepository'])->name('projects.ssh');
    Route::post('/servers', [PanelController::class, 'server'])->name('servers.store');
    Route::post('/assignments', [PanelController::class, 'assignment'])->name('assignments.store');
    Route::post('/deployments', [PanelController::class, 'deployment'])->name('deployments.store');
    Route::post('/targets/{target}/cancel', [PanelController::class, 'cancelTarget'])->name('targets.cancel');
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    });
});
