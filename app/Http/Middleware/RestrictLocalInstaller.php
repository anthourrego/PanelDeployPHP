<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictLocalInstaller
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.installer_local')) {
            abort_unless(
                PHP_SAPI === 'cli-server'
                && $request->server('REMOTE_ADDR') === '127.0.0.1'
                && $request->getHost() === '127.0.0.1'
                && $request->getPort() === 8000
                && ! $request->headers->has('Forwarded')
                && ! $request->headers->has('X-Forwarded-For')
                && ! $request->headers->has('X-Forwarded-Host')
                && ! $request->headers->has('X-Forwarded-Proto')
                && ! $request->headers->has('X-Forwarded-Port')
                && ! $request->headers->has('X-Forwarded-Prefix')
                && ! $request->headers->has('X-Real-IP')
                && ! $request->headers->has('Via'),
                404,
            );
        }

        return $next($request);
    }
}
