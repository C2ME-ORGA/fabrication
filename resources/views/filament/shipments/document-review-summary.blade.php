@php
    /** @var \Illuminate\Support\Collection<int, \App\DTO\ShipmentDocumentReviewRequirement> $requirements */
    $formatVersion = function ($requirement): string {
        $revision = $requirement->version->revision ?: '—';
        $number = $requirement->version->version_number ?? '—';

        return "v{$number} ({$revision})";
    };
@endphp

<div class="space-y-3 text-sm">
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="mb-2 font-semibold">Révisions à examiner</div>

        @if ($requirements->isEmpty())
            <p class="text-gray-500">Aucune nouvelle révision documentaire applicable.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-1 pr-3">Document</th>
                            <th class="py-1 pr-3">Révision libérée</th>
                            <th class="py-1 pr-3">Révision courante</th>
                            <th class="py-1 pr-3">Décision</th>
                            <th class="py-1">Fichier</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requirements as $requirement)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-2 pr-3">
                                    <div class="font-medium">{{ $requirement->reference() }}</div>
                                    <div class="text-xs text-gray-500">{{ $requirement->document->title }}</div>
                                </td>
                                <td class="py-2 pr-3">
                                    @if ($requirement->baseline)
                                        {{ $requirement->baseline->revision ?: '—' }}
                                        — v{{ $requirement->baseline->version_number ?? '—' }}
                                    @else
                                        <span class="text-warning-600">Aucun snapshot</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3">
                                    {{ $formatVersion($requirement) }}
                                    @if ($requirement->version->validated_at)
                                        <div class="text-xs text-gray-500">
                                            approuvée le {{ $requirement->version->validated_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2 pr-3">
                                    @if ($requirement->isBlocked())
                                        <span class="rounded bg-danger-100 px-2 py-0.5 font-semibold text-danger-700">
                                            Bloquante
                                        </span>
                                    @elseif ($requirement->isPending())
                                        <span class="rounded bg-warning-100 px-2 py-0.5 font-semibold text-warning-700">
                                            À examiner
                                        </span>
                                    @else
                                        <span class="rounded bg-success-100 px-2 py-0.5 font-semibold text-success-700">
                                            {{ $requirement->decision?->decision->getLabel() }}
                                        </span>
                                    @endif
                                    @if ($requirement->decision?->comment)
                                        <div class="mt-1 text-xs text-gray-500">{{ $requirement->decision->comment }}</div>
                                    @endif
                                </td>
                                <td class="py-2">
                                    @if (auth()->user()?->can('download', $requirement->version))
                                        <a class="text-primary-600 underline"
                                           target="_blank"
                                           rel="noopener"
                                           href="{{ route('filament.workshop.quality.documents.versions.download', $requirement->version) }}">
                                            Télécharger
                                        </a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
