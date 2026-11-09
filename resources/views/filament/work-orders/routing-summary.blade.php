@php
    $routing = $workOrder->workshopRouting;
    $routingLabel = $routing !== null
        ? "{$routing->code} — {$routing->name}"
        : ($workOrder->workshop_routing_id !== null
            ? 'Gamme source supprimée — opérations conservées'
            : 'Aucune gamme affectée');
    $operationsCount = $workOrder->operations()->count();
    $estimatedDuration = (int) $workOrder->operations()->sum('estimated_duration_minutes');
    $editable = $workOrder->isRoutingEditable();
@endphp

<div class="mb-4 space-y-3">
    <h3 class="text-sm font-medium text-gray-950 dark:text-white">Gamme atelier</h3>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Gamme</div>
            <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $routingLabel }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Opérations générées</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $operationsCount }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Durée estimée</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $estimatedDuration }} min</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">État</div>
            <div class="mt-1 text-lg font-semibold {{ $editable ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400' }}">
                {{ $editable ? 'Modifiable' : 'Verrouillée' }}
            </div>
        </div>
    </div>

    @if (! $editable)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            L'OF est libéré ou clôturé : la gamme, les opérations et leurs dépendances sont affichées en lecture seule.
        </p>
    @endif
</div>
