@php
    $counts = $summary['operations_by_status'] ?? [];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">OF libérés / en production</div>
                <div class="mt-2 text-3xl font-bold text-gray-950 dark:text-white">{{ $summary['released_work_orders'] ?? 0 }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Opérations en cours</div>
                <div class="mt-2 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $counts['in_progress'] ?? 0 }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Opérations bloquées</div>
                <div class="mt-2 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $counts['blocked'] ?? 0 }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Chronomètres actifs</div>
                <div class="mt-2 text-3xl font-bold text-success-600 dark:text-success-400">{{ $summary['active_timers'] ?? 0 }}</div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">En attente</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $counts['pending'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Prêtes</div>
                <div class="mt-2 text-2xl font-semibold text-info-600 dark:text-info-400">{{ $counts['ready'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Terminées</div>
                <div class="mt-2 text-2xl font-semibold text-success-600 dark:text-success-400">{{ $counts['completed'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Temps de production cumulé</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ \App\Support\DurationFormatter::format((int) ($summary['total_real_seconds'] ?? 0)) }}</div>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ $operationsUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-primary-600 px-4 py-2 font-semibold text-white shadow-sm hover:bg-primary-500">
                Voir les opérations
            </a>
            @if ($canSupervise)
                <a href="{{ $supervisionUrl }}" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                    Supervision atelier
                </a>
            @endif
        </div>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Qualité</h3>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ $qualityControlsUrl }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                        Contrôles qualité
                    </a>
                    <a href="{{ $nonConformitiesUrl }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                        Non-conformités
                    </a>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Taux de conformité</div>
                    <div class="mt-2 text-3xl font-bold text-success-600 dark:text-success-400">
                        @if (($quality['conformity_rate'] ?? null) !== null)
                            {{ number_format((float) $quality['conformity_rate'], 1, ',', ' ') }} %
                        @else
                            —
                        @endif
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Contrôles obligatoires clôturés conformes / non conformes</div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Contrôles en retard</div>
                    <div class="mt-2 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $quality['controls_late'] ?? 0 }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $quality['controls_pending'] ?? 0 }} contrôle(s) à réaliser</div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">NC ouvertes</div>
                    <div class="mt-2 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $quality['non_conformities_open'] ?? 0 }}</div>
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach (($quality['non_conformities_by_severity'] ?? []) as $severity => $count)
                            <x-filament::badge :color="\App\Enums\NonConformitySeverity::from($severity)->getColor()">
                                {{ \App\Enums\NonConformitySeverity::from($severity)->getLabel() }} : {{ $count }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Dérogations actives</div>
                    <div class="mt-2 text-3xl font-bold text-info-600 dark:text-info-400">{{ $quality['deviations_active'] ?? 0 }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $quality['controls_awaiting_validation'] ?? 0 }} contrôle(s) en attente de validation</div>
                </div>
            </div>
        </section>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Répartition des opérations par statut</h3>
            <div class="flex flex-wrap gap-2">
                @foreach ($statusLabels as $status => $label)
                    <span class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1 text-sm dark:border-white/10">
                        <x-filament::badge :color="$statusColors[$status] ?? 'gray'">{{ $label }}</x-filament::badge>
                        <span class="font-semibold tabular-nums">{{ $counts[$status] ?? 0 }}</span>
                    </span>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            @livewire(\App\Livewire\Workshop\ActiveTimers::class)
        </div>
    </div>
</x-filament-panels::page>
