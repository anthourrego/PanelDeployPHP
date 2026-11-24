<?php

use App\Http\Controllers\InstallerController;
use Illuminate\Support\Facades\Route;

Route::get('/instalacion', [InstallerController::class, 'show'])->name('installer.show');
Route::post('/instalacion/conexion', [InstallerController::class, 'connect'])->name('installer.connect');
Route::post('/instalacion/finalizar', [InstallerController::class, 'finish'])->name('installer.finish');
