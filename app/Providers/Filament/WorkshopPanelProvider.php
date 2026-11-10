<?php

namespace App\Providers\Filament;

use App\Filament\Resources\NonConformities\NonConformityResource;
use App\Filament\Resources\QualityControlTemplates\QualityControlTemplateResource;
use App\Filament\Resources\QualityControlTemplateVersions\QualityControlTemplateVersionResource;
use App\Filament\Resources\WorkshopRoutings\WorkshopRoutingResource;
use App\Filament\Workshop\Pages\WorkshopDashboard;
use App\Filament\Workshop\Pages\WorkshopSupervision;
use App\Filament\Workshop\Resources\QualityControls\QualityControlResource;
use App\Filament\Workshop\Resources\WorkshopOperations\WorkshopOperationResource;
use App\Http\Controllers\NonConformityAttachmentDownloadController;
use App\Http\Controllers\QualityControlResultAttachmentDownloadController;
use App\Http\Controllers\QualityDeviationAttachmentDownloadController;
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
use Illuminate\Support\Facades\Route;
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
                WorkshopOperationResource::class,
                QualityControlResource::class,
                QualityControlTemplateResource::class,
                QualityControlTemplateVersionResource::class,
                NonConformityResource::class,
            ])
            ->pages([
                WorkshopDashboard::class,
                WorkshopSupervision::class,
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('/quality/result-attachments/{attachment}/download', QualityControlResultAttachmentDownloadController::class)
                    ->name('quality.result-attachments.download');

                Route::get('/quality/non-conformity-attachments/{attachment}/download', NonConformityAttachmentDownloadController::class)
                    ->name('quality.non-conformity-attachments.download');

                Route::get('/quality/deviation-attachments/{attachment}/download', QualityDeviationAttachmentDownloadController::class)
                    ->name('quality.deviation-attachments.download');
            })
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
            ->homeUrl(fn (): string => WorkshopDashboard::getUrl(panel: 'workshop'));
    }
}
