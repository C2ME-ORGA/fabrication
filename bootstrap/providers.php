<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\WorkshopPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    WorkshopPanelProvider::class,
];
