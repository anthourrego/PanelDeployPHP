<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-users', fn (User $user): bool => $user->is_admin);

        // Evita que el idioma del login de SQL Server reinterprete las fechas ISO (p. ej. dmy/ydm).
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() === 'sqlsrv') {
                $event->connection->getPdo()->exec('SET DATEFORMAT ymd');
            }
        });
    }
}
