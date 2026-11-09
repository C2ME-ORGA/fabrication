@php
    $real = $summary->realSeconds;
    $estimated = $summary->estimatedSeconds;
    $variance = $summary->varianceSeconds;
    $percent = $summary->consumptionPercent;
@endphp

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="text-xs text-gray-500 dark:text-gray-400">Temps estimé</div>
        <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
            {{ \App\Support\DurationFormatter::format($estimated) }}
        </div>
    </div>
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="text-xs text-gray-500 dark:text-gray-400">Temps réel</div>
        <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
            {{ \App\Support\DurationFormatter::format($real) }}
        </div>
    </div>
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="text-xs text-gray-500 dark:text-gray-400">Écart</div>
        <div class="mt-1 text-lg font-semibold {{ $variance > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
            {{ $variance > 0 ? '+' : '' }}{{ \App\Support\DurationFormatter::format(abs($variance)) }}
        </div>
    </div>
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="text-xs text-gray-500 dark:text-gray-400">Consommation estimé</div>
        <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
            {{ $percent !== null ? number_format($percent, 1, ',', ' ').' %' : '—' }}
        </div>
    </div>
</div>

@if ($summary->byOperator !== [])
    @php $operatorNames = \App\Models\User::query()->whereIn('id', array_keys($summary->byOperator))->pluck('name', 'id')->all(); @endphp
    <div class="mt-3 text-sm text-gray-600 dark:text-gray-300">
        <span class="font-medium text-gray-950 dark:text-white">Temps par opérateur :</span>
        @foreach ($summary->byOperator as $operatorId => $seconds)
            <span class="mr-3 inline-block">{{ $operatorNames[$operatorId] ?? "Opérateur #{$operatorId}" }} : {{ \App\Support\DurationFormatter::format($seconds) }}</span>
        @endforeach
    </div>
@endif
