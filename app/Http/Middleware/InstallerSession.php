<?php

namespace App\Http\Middleware;

use App\Services\Installer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

class InstallerSession
{
    public function __construct(private Installer $installer, private SessionManager $sessions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $local = $this->installer->isLocal();
        if ($local) {
            abort_unless(RestrictLocalInstaller::allowsLocalRequest($request), 404);
        }
        abort_unless(($request->isSecure() || config('app.installer_local') || $local) && ! config('app.debug') && $this->installer->isEnabled(), 404);

        $previous = config('session');
        config([
            'session.driver' => 'file',
            'session.cookie' => 'panel_install_session',
            'session.secure' => $request->isSecure(),
            'session.same_site' => 'strict',
            'session.encrypt' => true,
        ]);
        $this->sessions->forgetDrivers();

        try {
            $response = $next($request);
        } finally {
            config(['session' => $previous]);
            $this->sessions->forgetDrivers();
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

        return $response;
    }
}
