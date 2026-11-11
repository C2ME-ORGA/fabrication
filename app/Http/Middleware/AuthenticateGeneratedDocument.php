<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentification des routes PDF accessibles aux deux panneaux Filament.
 *
 * FilamentAuthenticate dépend du panneau courant et refuserait un responsable
 * atelier sur le panneau admin. Cette middleware vérifie uniquement la session
 * web ; les Policies contrôlent ensuite l'accès au document.
 */
class AuthenticateGeneratedDocument
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        return $next($request);
    }
}
