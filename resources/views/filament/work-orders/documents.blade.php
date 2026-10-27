<div class="space-y-6 text-sm">
    <div>
        <h3 class="font-medium">Documents applicables actuellement</h3>
        @if ($applicableDocuments->isEmpty())
            <p class="text-gray-500 dark:text-gray-400">Aucun document actif rattaché à l’affaire ou à cet OF.</p>
        @else
            <table class="mt-2 w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-1 pr-4">Document</th>
                        <th class="py-1 pr-4">Catégorie</th>
                        <th class="py-1 pr-4">Dernière version</th>
                        <th class="py-1 pr-4">Version applicable</th>
                        <th class="py-1 pr-4">Téléchargement</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($applicableDocuments as $document)
                        @php
                            $approved = $document->latestApprovedVersion;
                        @endphp
                        <tr class="border-b">
                            <td class="py-1 pr-4">
                                <a class="underline" href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl('view', ['record' => $document]) }}">
                                    {{ $document->title }}
                                </a>
                                @if ($document->reference)
                                    <span class="text-gray-500">({{ $document->reference }})</span>
                                @endif
                            </td>
                            <td class="py-1 pr-4">{{ $document->category->getLabel() }}</td>
                            <td class="py-1 pr-4">
                                {{ $document->currentVersion?->revision ?? '—' }}
                                @if ($document->currentVersion)
                                    — {{ $document->currentVersion->status->getLabel() }}
                                @endif
                            </td>
                            <td class="py-1 pr-4">
                                @if ($approved)
                                    {{ $approved->revision }} — approuvée
                                @else
                                    <span class="text-amber-600">Aucune version approuvée</span>
                                @endif
                            </td>
                            <td class="py-1 pr-4">
                                @if ($approved)
                                    <a class="underline" href="{{ route('documents.versions.download', $approved) }}">
                                        {{ $approved->revision }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>
        <h3 class="font-medium">Références figées à la libération</h3>
        @if ($releaseSnapshots->isEmpty())
            <p class="text-gray-500 dark:text-gray-400">Aucune référence documentaire figée lors de la libération.</p>
        @else
            <table class="mt-2 w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-1 pr-4">Document</th>
                        <th class="py-1 pr-4">Version</th>
                        <th class="py-1 pr-4">Validée par</th>
                        <th class="py-1 pr-4">Validée le</th>
                        <th class="py-1 pr-4">Figée le</th>
                        <th class="py-1 pr-4">Impact</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($releaseSnapshots as $snapshot)
                        <tr class="border-b">
                            <td class="py-1 pr-4">
                                <a class="underline" href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl('view', ['record' => $snapshot->document]) }}">
                                    {{ $snapshot->title }}
                                </a>
                                @if ($snapshot->reference)
                                    <span class="text-gray-500">({{ $snapshot->reference }})</span>
                                @endif
                            </td>
                            <td class="py-1 pr-4">{{ $snapshot->revision }}</td>
                            <td class="py-1 pr-4">{{ $snapshot->validator?->name ?? '—' }}</td>
                            <td class="py-1 pr-4">{{ $snapshot->validated_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="py-1 pr-4">{{ $snapshot->captured_at->format('d/m/Y H:i') }}</td>
                            <td class="py-1 pr-4">
                                @php
                                    $newer = $snapshots->firstWhere('document_id', $snapshot->document_id)?->newer_approved_version;
                                @endphp
                                @if ($newer)
                                    <span class="text-amber-600">Nouvelle révision {{ $newer->revision }} approuvée</span>
                                @else
                                    À jour
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>
        <h3 class="font-medium">Remises en revue explicites</h3>
        @if ($snapshots->where('reason', \App\Enums\DocumentSnapshotReason::ReReview)->isEmpty())
            <p class="text-gray-500 dark:text-gray-400">Aucune remise en revue documentaire enregistrée.</p>
        @else
            <table class="mt-2 w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-1 pr-4">Date</th>
                        <th class="py-1 pr-4">Document</th>
                        <th class="py-1 pr-4">Version</th>
                        <th class="py-1 pr-4">Motif</th>
                        <th class="py-1 pr-4">Auteur</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($snapshots->where('reason', \App\Enums\DocumentSnapshotReason::ReReview) as $snapshot)
                        <tr class="border-b">
                            <td class="py-1 pr-4">{{ $snapshot->captured_at->format('d/m/Y H:i') }}</td>
                            <td class="py-1 pr-4">
                                <a class="underline" href="{{ \App\Filament\Resources\Documents\DocumentResource::getUrl('view', ['record' => $snapshot->document]) }}">
                                    {{ $snapshot->title }}
                                </a>
                            </td>
                            <td class="py-1 pr-4">{{ $snapshot->revision }}</td>
                            <td class="py-1 pr-4">{{ $snapshot->comment ?? '—' }}</td>
                            <td class="py-1 pr-4">{{ $snapshot->capturedBy?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
