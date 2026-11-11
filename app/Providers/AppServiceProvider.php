<?php

namespace App\Providers;

use App\Contracts\WorkOrderQualityReleaseSource;
use App\Services\DatabaseWorkOrderQualityReleaseSource;
use App\Services\DomPdfRenderer;
use App\Services\PdfRenderer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            WorkOrderQualityReleaseSource::class,
            DatabaseWorkOrderQualityReleaseSource::class,
        );

        $this->app->bind(PdfRenderer::class, DomPdfRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
