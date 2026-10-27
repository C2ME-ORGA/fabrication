<div class="text-sm">
    @if ($latest === null)
        <p class="text-gray-500 dark:text-gray-400">
            Aucune version approuvée : ce document n’est pas applicable à la libération d’un OF.
        </p>
    @elseif ($workOrders->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">
            Aucun OF libéré, en cours ou en contrôle qualité n’utilise une révision antérieure à
            {{ $latest->revision }} (version {{ $latest->version_number }}).
        </p>
    @else
        <p class="font-medium">
            {{ $workOrders->count() }} OF libéré(s) utilisent encore une révision antérieure à
            {{ $latest->revision }} (version {{ $latest->version_number }}).
        </p>
        <table class="mt-2 w-full text-left">
            <thead>
                <tr class="border-b">
                    <th class="py-1 pr-4">OF</th>
                    <th class="py-1 pr-4">Affaire</th>
                    <th class="py-1 pr-4">Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    <tr class="border-b">
                        <td class="py-1 pr-4">
                            <a class="underline" href="{{ \App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('view', ['record' => $workOrder]) }}">
                                {{ $workOrder->number }}
                            </a>
                        </td>
                        <td class="py-1 pr-4">{{ $workOrder->job?->code ?? '—' }}</td>
                        <td class="py-1 pr-4">{{ $workOrder->status->getLabel() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
