<?php

namespace App\Filament\Workshop\Pages;

use App\Services\WorkshopDashboardService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WorkshopSupervision extends Page
{
    protected string $view = 'filament.workshop.pages.supervision';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Supervision';

    protected static ?string $title = 'Supervision atelier';

    protected static ?string $slug = 'supervision';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $supervision = [];

    /** @var array<string, mixed> */
    public array $quality = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canManageOperationTimes() === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $service = app(WorkshopDashboardService::class);
        $data = $service->supervision();

        $data['work_order_summaries'] = collect($data['work_order_summaries'])
            ->map(fn ($summary): array => [
                'realSeconds' => $summary->realSeconds,
                'estimatedSeconds' => $summary->estimatedSeconds,
                'varianceSeconds' => $summary->varianceSeconds,
                'consumptionPercent' => $summary->consumptionPercent,
            ])
            ->all();

        $this->summary = $service->summary();
        $this->quality = $service->qualitySupervision();
        $this->supervision = [
            'blocked_operations' => $data['blocked_operations'],
            'overdue_operations' => $data['overdue_operations'],
            'work_orders_in_progress' => $data['work_orders_in_progress'],
            'stale_operations' => $data['stale_operations'],
            'active_operators_count' => $data['active_operators_count'],
            'work_order_summaries' => $data['work_order_summaries'],
            'work_orders' => $data['work_orders'],
        ];
    }
}
