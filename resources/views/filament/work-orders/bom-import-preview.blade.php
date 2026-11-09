@php
    $formatInteger = static fn (int $value): string => number_format($value, 0, ',', ' ');
@endphp

<div class="space-y-4 text-sm">
    @if ($error)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
            <p class="font-medium">Import impossible</p>
            <p class="mt-1">{{ $error }}</p>
        </div>
    @elseif ($preview)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Lignes</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $formatInteger($preview['rows_total']) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Valides</div>
                <div class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400">{{ $formatInteger($preview['rows_valid']) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Invalides</div>
                <div class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400">{{ $formatInteger($preview['rows_invalid']) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Nouvelles pièces</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $formatInteger($preview['new_items_count']) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Fusions prévues</div>
                <div class="mt-1 text-lg font-semibold text-warning-600 dark:text-warning-400">{{ $formatInteger($preview['merged_rows_count']) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Quantité ajoutée</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $formatInteger($preview['planned_quantity_added']) }}</div>
            </div>
        </div>

        @if ($preview['valid'])
            <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">
                Aucune erreur détectée. La confirmation relancera toute la validation côté serveur avant l’écriture.
            </div>
        @else
            <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
                Le fichier contient des erreurs : la confirmation est impossible tant qu’elles ne sont pas corrigées.
            </div>
        @endif

        @if (! empty($preview['errors']))
            <div>
                <h4 class="font-medium text-gray-950 dark:text-white">Erreurs détectées</h4>
                <div class="mt-2 max-h-72 overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-2">Ligne</th>
                                <th class="px-3 py-2">Colonne</th>
                                <th class="px-3 py-2">Valeur</th>
                                <th class="px-3 py-2">Motif</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['errors'] as $error)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    <td class="px-3 py-2">{{ $error['line_number'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $error['column'] ?? '—' }}</td>
                                    <td class="max-w-48 truncate px-3 py-2">{{ $error['value'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if (! empty($preview['plans']))
            <div>
                <h4 class="font-medium text-gray-950 dark:text-white">Lignes et fusions prévues</h4>
                <div class="mt-2 max-h-80 overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-2">Ligne</th>
                                <th class="px-3 py-2">Repère</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">Quantité ajoutée</th>
                                <th class="px-3 py-2">Quantité résultante</th>
                                <th class="px-3 py-2">Détail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['plans'] as $plan)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    <td class="px-3 py-2">{{ $plan['line_number'] }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $plan['mark'] }}</td>
                                    <td class="px-3 py-2">{{ $plan['action_label'] }}</td>
                                    <td class="px-3 py-2">{{ $formatInteger($plan['planned_quantity']) }}</td>
                                    <td class="px-3 py-2">{{ $formatInteger($plan['resulting_planned_quantity']) }}</td>
                                    <td class="px-3 py-2">{{ $plan['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
