<?php

use App\Http\Controllers\AgentController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateAgent::class, 'throttle:60,1'])->prefix('agent')->group(function () {
    Route::post('/claim', [AgentController::class, 'claim']);
    Route::post('/targets/{target}/report', [AgentController::class, 'report'])->whereNumber('target');
});
