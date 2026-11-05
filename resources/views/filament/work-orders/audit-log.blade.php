<div class="space-y-4 text-sm">
    <div>
        <h3 class="font-medium">Historique des actions</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Journal immuable des actions effectuées sur cet ordre de fabrication, du plus récent au plus ancien.
        </p>
    </div>

    @if ($auditLogs->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">Aucune action n'a encore été enregistrée.</p>
    @else
        <table class="mt-2 w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">Date et heure</th>
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">Numéro d'OF</th>
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">Utilisateur</th>
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">Action</th>
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">État avant</th>
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">État après</th>
                    <th class="py-2 pr-4 text-left font-semibold text-gray-700 dark:text-gray-300">Motif / commentaire</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($auditLogs as $log)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="py-2 pr-4">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                        <td class="py-2 pr-4">{{ $log->work_order_number ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $log->user?->name ?? 'Système' }}</td>
                        <td class="py-2 pr-4">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium
                                @if ($log->event === 'created') bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300
                                @elseif ($log->event === 'released') bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300
                                @elseif ($log->event === 'started') bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300
                                @elseif ($log->event === 'quality_check') bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300
                                @elseif ($log->event === 'completed') bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300
                                @elseif ($log->event === 'cancelled') bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300
                                @else bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 @endif
                            ">
                                {{ \App\Enums\WorkOrderAuditEvent::from($log->event)->getLabel() }}
                            </span>
                        </td>
                        <td class="py-2 pr-4">
                            {{ $log->from_status ? \App\Enums\WorkOrderStatus::from($log->from_status)->getLabel() : '—' }}
                        </td>
                        <td class="py-2 pr-4">
                            {{ $log->to_status ? \App\Enums\WorkOrderStatus::from($log->to_status)->getLabel() : '—' }}
                        </td>
                        <td class="py-2 pr-4">{{ $log->comment ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
