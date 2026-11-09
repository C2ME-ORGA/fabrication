<?php

namespace App\Providers\Filament;

use App\Filament\Resources\WorkshopRoutings\WorkshopRoutingResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panneau atelier séparé du panneau d'administration.
 *
 * Il partage l'authentification web mais n'expose que les ressources
 * autorisées par leurs Policies. Les opérateurs standards n'ont pas accès à
 * la gestion des gammes ; les responsables atelier y accèdent sans recevoir
 * les droits d'administration.
 */
class WorkshopPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workshop')
            ->path('workshop')
            ->login()
            ->brandName('Atelier C2ME')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->resources([
                WorkshopRoutingResource::class,
            ])
            ->pages([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->homeUrl(fn (): string => WorkshopRoutingResource::getUrl('index'));
    }
}
