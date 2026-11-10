<?php

namespace App\Filament\Workshop\Pages;

use App\Enums\WorkshopOperationStatus;
use App\Filament\Resources\NonConformities\NonConformityResource;
use App\Filament\Workshop\Resources\QualityControls\QualityControlResource;
use App\Filament\Workshop\Resources\WorkshopOperations\WorkshopOperationResource;
use App\Services\WorkshopDashboardService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WorkshopDashboard extends Page
{
    protected string $view = 'filament.workshop.pages.dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static ?string $title = 'Tableau de bord atelier';

    protected static ?string $slug = 'dashboard';

    protected static ?int $navigationSort = -1;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $quality = [];

    /** @var array<string, string> */
    public array $statusLabels = [];

    /** @var array<string, string> */
    public array $statusColors = [];

    public string $operationsUrl = '';

    public string $supervisionUrl = '';

    public string $qualityControlsUrl = '';

    public string $nonConformitiesUrl = '';

    public bool $canSupervise = false;

    public function mount(): void
    {
        $service = app(WorkshopDashboardService::class);

        $this->summary = $service->summary();
        $this->quality = $service->qualitySummary();
        $this->statusLabels = collect(WorkshopOperationStatus::cases())
            ->mapWithKeys(fn (WorkshopOperationStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
        $this->statusColors = collect(WorkshopOperationStatus::cases())
            ->mapWithKeys(fn (WorkshopOperationStatus $status): array => [$status->value => $status->getColor()])
            ->all();
        $this->operationsUrl = WorkshopOperationResource::getUrl('index', panel: 'workshop');
        $this->supervisionUrl = WorkshopSupervision::getUrl(panel: 'workshop');
        $this->qualityControlsUrl = QualityControlResource::getUrl('index', panel: 'workshop');
        $this->nonConformitiesUrl = NonConformityResource::getUrl('index', panel: 'workshop');
        $this->canSupervise = auth()->user()?->canManageOperationTimes() === true;
    }
}
