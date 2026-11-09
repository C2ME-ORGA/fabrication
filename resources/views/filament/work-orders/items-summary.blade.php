@php
    $formatInteger = static fn (int $value): string => number_format($value, 0, ',', ' ');
    $formatWeight = static fn (float $value): string => number_format($value, 3, ',', ' ');
@endphp

<div class="mb-4 space-y-3">
    <h3 class="text-sm font-medium text-gray-950 dark:text-white">Récapitulatif de production</h3>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Quantité prévue</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $formatInteger($summary->planned) }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Fabriqué</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $formatInteger($summary->produced) }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Accepté</div>
            <div class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400">{{ $formatInteger($summary->accepted) }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Rebuté</div>
            <div class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400">{{ $formatInteger($summary->rejected) }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Restant à fabriquer</div>
            <div class="mt-1 text-lg font-semibold text-warning-600 dark:text-warning-400">{{ $formatInteger($summary->remaining) }}</div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">Poids total indicatif</div>
            <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $formatWeight($summary->totalWeightKg) }} kg</div>
        </div>
    </div>
</div>
