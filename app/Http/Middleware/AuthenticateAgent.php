<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure()) {
            return response()->json(['error' => 'HTTPS requerido.'], 426);
        }
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/^([1-9][0-9]*)\.([a-f0-9]{64})$/D', $token, $parts)) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }
        $server = Server::find($parts[1]);
        if (! $server || ! $server->agent_token_hash || ! hash_equals($server->agent_token_hash, hash('sha256', $parts[2]))) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }
        $request->attributes->set('agent_server', $server);
        $request->attributes->set('agent_token_hash', hash('sha256', $parts[2]));

        return $next($request);
    }
}
