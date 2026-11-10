@php
    $counts = $summary['operations_by_status'] ?? [];
    $blocked = $supervision['blocked_operations'] ?? collect();
    $overdue = $supervision['overdue_operations'] ?? collect();
    $inProgressWorkOrders = $supervision['work_orders_in_progress'] ?? collect();
    $stale = $supervision['stale_operations'] ?? collect();
    $workOrders = $supervision['work_orders'] ?? collect();
    $workOrderSummaries = $supervision['work_order_summaries'] ?? [];
    $qualityPending = $quality['pending_controls'] ?? collect();
    $qualityOverdue = $quality['overdue_controls'] ?? collect();
    $qualityAwaitingValidation = $quality['awaiting_validation_controls'] ?? collect();
    $qualityOverdueNc = $quality['overdue_non_conformities'] ?? collect();
    $qualityExpiringDeviations = $quality['expiring_deviations'] ?? collect();
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Opérateurs avec chrono</div>
                <div class="mt-2 text-3xl font-bold text-success-600 dark:text-success-400">{{ $supervision['active_operators_count'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Opérations bloquées</div>
                <div class="mt-2 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $counts['blocked'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Opérations en retard temps</div>
                <div class="mt-2 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $overdue->count() }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Temps de production cumulé</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ \App\Support\DurationFormatter::format((int) ($summary['total_real_seconds'] ?? 0)) }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            @livewire(\App\Livewire\Workshop\ActiveTimers::class)
        </div>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Qualité</h3>
                <a href="{{ \App\Filament\Workshop\Resources\QualityControls\QualityControlResource::getUrl('index', panel: 'workshop') }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                    Tous les contrôles
                </a>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Contrôles à réaliser</div>
                    <div class="mt-2 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $quality['pending_controls_count'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Contrôles en retard</div>
                    <div class="mt-2 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $quality['overdue_controls_count'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">En attente de validation</div>
                    <div class="mt-2 text-3xl font-bold text-info-600 dark:text-info-400">{{ $quality['awaiting_validation_controls_count'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">NC en retard</div>
                    <div class="mt-2 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $quality['overdue_non_conformities_count'] ?? 0 }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Dérogations expirant sous 7 jours</div>
                <div class="mt-2 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $quality['expiring_deviations_count'] ?? 0 }}</div>
            </div>
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Contrôles qualité à réaliser</h3>
            @if ($qualityPending->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucun contrôle en attente.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[860px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Contrôle</th><th class="px-3 py-2">Opération</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2">Exigible</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($qualityPending as $control)
                                <tr>
                                    <td class="px-3 py-2">{{ $control->workOrder?->number }}</td>
                                    <td class="px-3 py-2">{{ $control->template_code }} — {{ $control->template_name }}</td>
                                    <td class="px-3 py-2">{{ $control->operation?->code ?? '—' }}</td>
                                    <td class="px-3 py-2"><x-filament::badge :color="$control->status->getColor()">{{ $control->status->getLabel() }}</x-filament::badge></td>
                                    <td class="px-3 py-2">{{ $control->required_by_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <a class="font-semibold text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Workshop\Resources\QualityControls\QualityControlResource::getUrl('view', ['record' => $control], panel: 'workshop') }}">Ouvrir</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Contrôles en retard</h3>
            @if ($qualityOverdue->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucun contrôle en retard.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Contrôle</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2">Exigible depuis</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($qualityOverdue as $control)
                                <tr>
                                    <td class="px-3 py-2">{{ $control->workOrder?->number }}</td>
                                    <td class="px-3 py-2">{{ $control->template_code }} — {{ $control->template_name }}</td>
                                    <td class="px-3 py-2"><x-filament::badge :color="$control->status->getColor()">{{ $control->status->getLabel() }}</x-filament::badge></td>
                                    <td class="px-3 py-2 text-danger-600 dark:text-danger-400">{{ $control->required_by_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <a class="font-semibold text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Workshop\Resources\QualityControls\QualityControlResource::getUrl('view', ['record' => $control], panel: 'workshop') }}">Ouvrir</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Contrôles en attente de validation</h3>
            @if ($qualityAwaitingValidation->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucun résultat en attente de validation.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Contrôle</th><th class="px-3 py-2">Opération</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($qualityAwaitingValidation as $control)
                                <tr>
                                    <td class="px-3 py-2">{{ $control->workOrder?->number }}</td>
                                    <td class="px-3 py-2">{{ $control->template_code }} — {{ $control->template_name }}</td>
                                    <td class="px-3 py-2">{{ $control->operation?->code ?? '—' }}</td>
                                    <td class="px-3 py-2"><x-filament::badge :color="$control->status->getColor()">{{ $control->status->getLabel() }}</x-filament::badge></td>
                                    <td class="px-3 py-2 text-right">
                                        <a class="font-semibold text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Workshop\Resources\QualityControls\QualityControlResource::getUrl('view', ['record' => $control], panel: 'workshop') }}">Valider</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">NC en dépassement d'échéance</h3>
            @if ($qualityOverdueNc->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucune non-conformité en retard.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">Référence</th><th class="px-3 py-2">OF</th><th class="px-3 py-2">Gravité</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2">Échéance</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($qualityOverdueNc as $nonConformity)
                                <tr>
                                    <td class="px-3 py-2 font-semibold">{{ $nonConformity->reference }}</td>
                                    <td class="px-3 py-2">{{ $nonConformity->workOrder?->number ?? $nonConformity->work_order_number }}</td>
                                    <td class="px-3 py-2"><x-filament::badge :color="$nonConformity->severity->getColor()">{{ $nonConformity->severity->getLabel() }}</x-filament::badge></td>
                                    <td class="px-3 py-2"><x-filament::badge :color="$nonConformity->status->getColor()">{{ $nonConformity->status->getLabel() }}</x-filament::badge></td>
                                    <td class="px-3 py-2 text-danger-600 dark:text-danger-400">
                                        {{ $nonConformity->due_date?->format('d/m/Y') ?? $nonConformity->verification_due_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <a class="font-semibold text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Resources\NonConformities\NonConformityResource::getUrl('view', ['record' => $nonConformity], panel: 'workshop') }}">Ouvrir</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Dérogations expirant sous 7 jours</h3>
            @if ($qualityExpiringDeviations->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucune dérogation à échéance proche.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">Référence</th><th class="px-3 py-2">OF</th><th class="px-3 py-2">Cible</th><th class="px-3 py-2">Expire le</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($qualityExpiringDeviations as $deviation)
                                <tr>
                                    <td class="px-3 py-2 font-semibold">{{ $deviation->reference }}</td>
                                    <td class="px-3 py-2">{{ $deviation->workOrder?->number ?? $deviation->work_order_number }}</td>
                                    <td class="px-3 py-2">{{ $deviation->targetDescription() }}</td>
                                    <td class="px-3 py-2 text-warning-600 dark:text-warning-400">{{ $deviation->expires_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Opérations bloquées</h3>
            @if ($blocked->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucune opération bloquée.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Opération</th><th class="px-3 py-2">Motif</th><th class="px-3 py-2">Depuis</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($blocked as $operation)
                                <tr>
                                    <td class="px-3 py-2">{{ $operation->workOrder?->number }}</td>
                                    <td class="px-3 py-2">{{ $operation->code }} — {{ $operation->name }}</td>
                                    <td class="px-3 py-2">{{ $operation->blocked_reason ?: '—' }}</td>
                                    <td class="px-3 py-2">{{ $operation->blocked_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Opérations en dépassement de temps estimé</h3>
            @if ($overdue->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucun dépassement constaté.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Opération</th><th class="px-3 py-2 text-right">Estimé</th><th class="px-3 py-2 text-right">Réel</th><th class="px-3 py-2 text-right">Écart</th><th class="px-3 py-2 text-right">%</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($overdue as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['operation']->workOrder?->number }}</td>
                                    <td class="px-3 py-2">{{ $row['operation']->code }} — {{ $row['operation']->name }}</td>
                                    <td class="px-3 py-2 text-right">{{ \App\Support\DurationFormatter::format($row['estimated_seconds']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ \App\Support\DurationFormatter::format($row['real_seconds']) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold text-danger-600 dark:text-danger-400">+{{ \App\Support\DurationFormatter::format($row['variance_seconds']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['consumption_percent'] !== null ? number_format($row['consumption_percent'], 1, ',', ' ').' %' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">OF avec opérations en cours</h3>
            @if ($inProgressWorkOrders->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucun OF en cours.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Affaire</th><th class="px-3 py-2 text-right">En cours</th><th class="px-3 py-2 text-right">Bloquées</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($inProgressWorkOrders as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['work_order']->number }}</td>
                                    <td class="px-3 py-2">{{ $row['work_order']->job?->code ?? '—' }} — {{ $row['work_order']->job?->name ?? '' }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $row['in_progress_count'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['blocked_count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Opérations sans activité récente (24 h)</h3>
            @if ($stale->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucune opération inactive.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Opération</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2">Dernière mise à jour</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($stale as $operation)
                                <tr>
                                    <td class="px-3 py-2">{{ $operation->workOrder?->number }}</td>
                                    <td class="px-3 py-2">{{ $operation->code }} — {{ $operation->name }}</td>
                                    <td class="px-3 py-2"><x-filament::badge :color="$operation->status->getColor()">{{ $operation->status->getLabel() }}</x-filament::badge></td>
                                    <td class="px-3 py-2">{{ $operation->updated_at?->timezone('Europe/Paris')->format('d/m/Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Temps consommés par OF</h3>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr><th class="px-3 py-2">OF</th><th class="px-3 py-2">Affaire</th><th class="px-3 py-2 text-right">Réel</th><th class="px-3 py-2 text-right">Estimé</th><th class="px-3 py-2 text-right">Écart</th><th class="px-3 py-2 text-right">%</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($workOrders as $workOrder)
                            @php $time = $workOrderSummaries[$workOrder->getKey()] ?? null; @endphp
                            <tr>
                                <td class="px-3 py-2">{{ $workOrder->number }}</td>
                                <td class="px-3 py-2">{{ $workOrder->job?->code ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ \App\Support\DurationFormatter::format((int) ($time['realSeconds'] ?? 0)) }}</td>
                                <td class="px-3 py-2 text-right">{{ \App\Support\DurationFormatter::format((int) ($time['estimatedSeconds'] ?? 0)) }}</td>
                                <td class="px-3 py-2 text-right {{ ($time['varianceSeconds'] ?? 0) > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                    {{ ($time['varianceSeconds'] ?? 0) > 0 ? '+' : '' }}{{ \App\Support\DurationFormatter::format(abs((int) ($time['varianceSeconds'] ?? 0))) }}
                                </td>
                                <td class="px-3 py-2 text-right">{{ ($time['consumptionPercent'] ?? null) !== null ? number_format($time['consumptionPercent'], 1, ',', ' ').' %' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-gray-500 dark:text-gray-400">Aucun OF actif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
