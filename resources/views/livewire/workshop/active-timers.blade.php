@php
    $formatDuration = $formatDuration ?? fn (int $seconds): string => \App\Support\DurationFormatter::format($seconds, true);
@endphp

<div wire:poll.5s class="space-y-3">
    <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Chronomètres actifs</h3>
        <span class="text-xs text-gray-500 dark:text-gray-400">Actualisation serveur toutes les 5 secondes</span>
    </div>

    @if ($entries->isEmpty())
        <p class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
            Aucun chronomètre actif.
        </p>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Opérateur</th>
                        <th class="px-3 py-2">Opération</th>
                        <th class="px-3 py-2">OF / affaire</th>
                        <th class="px-3 py-2">Début</th>
                        <th class="px-3 py-2 text-right">Durée</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($entries as $entry)
                        <tr class="align-top">
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">
                                {{ $entry->user?->name ?? 'Opérateur inconnu' }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-950 dark:text-white">
                                    {{ $entry->operation?->code }} — {{ $entry->operation?->name }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $entry->operation?->workOrder?->number }}
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ $entry->operation?->workOrder?->job?->code ?? '—' }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $entry->operation?->workOrder?->job?->name ?? '' }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                {{ $entry->started_at?->timezone('Europe/Paris')->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-warning-700 dark:text-warning-300">
                                {{ $formatDuration(max(0, $now->getTimestamp() - $entry->started_at->getTimestamp())) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
