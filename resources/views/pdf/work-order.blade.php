@php
    /** @var \App\DTO\WorkOrderPrintData $data */
    $visas = collect($data->visas)->keyBy('type');
    $formatDate = fn ($value): string => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
        : '—';
    $formatQty = fn (int $value): string => number_format($value, 0, ',', ' ');
    $statusClass = fn (string $status): string => match ($status) {
        \App\DTO\WorkOrderPrintVisa::STATUS_EFFECTUE => 'status-ok',
        \App\DTO\WorkOrderPrintVisa::STATUS_NON_RENSEIGNE => 'status-warn',
        default => 'status-missing',
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $data->workOrder->number }} — Ordre de fabrication</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm 16mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 9.5px; line-height: 1.35; }
        h1 { font-size: 20px; margin: 0; color: #111827; }
        h2 { font-size: 13px; margin: 18px 0 7px; padding-bottom: 3px; border-bottom: 1px solid #9ca3af; color: #111827; }
        h3 { font-size: 10px; margin: 0 0 3px; color: #374151; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5px; }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .header td { vertical-align: top; }
        .company { font-size: 18px; font-weight: bold; letter-spacing: .5px; }
        .doc-title { text-align: right; }
        .doc-title .number { font-size: 16px; font-weight: bold; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .meta th, .meta td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
        .meta th { width: 22%; background: #f3f4f6; font-weight: bold; color: #374151; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th, table.data td { border: 1px solid #d1d5db; padding: 3px 4px; vertical-align: top; }
        table.data th { background: #f3f4f6; font-weight: bold; color: #374151; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .section { page-break-inside: avoid; }
        .page-break { page-break-before: always; }
        .right { text-align: right; }
        .center { text-align: center; }
        .badge { display: inline-block; padding: 1px 4px; border-radius: 3px; font-size: 8px; font-weight: bold; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-warn { background: #fef3c7; color: #92400e; }
        .status-missing { background: #fee2e2; color: #991b1b; }
        .visa-grid { width: 100%; border-collapse: collapse; }
        .visa-grid td { width: 25%; border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        .visa-title { font-weight: bold; margin-bottom: 4px; }
        .visa-name { font-size: 10px; font-weight: bold; }
        .footer { margin-top: 14px; border-top: 1px solid #9ca3af; padding-top: 4px; font-size: 8px; color: #6b7280; }
        .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 7px; word-break: break-all; }
        .instructions { font-size: 8.5px; color: #4b5563; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="company">{{ $companyName }}</div>
                <div class="muted">Charpente métallique — fabrication</div>
            </td>
            <td class="doc-title">
                <div class="doc-title-label">ORDRE DE FABRICATION</div>
                <div class="number">{{ $data->workOrder->number }}</div>
                <div class="muted">Révision : {{ $data->ofRevision }}</div>
            </td>
        </tr>
    </table>

    <h2>Identification</h2>
    <table class="meta">
        <tr>
            <th>Affaire</th>
            <td>{{ $data->job->code }} — {{ $data->job->name }}</td>
            <th>Client</th>
            <td>{{ $data->job->client_name }}</td>
        </tr>
        <tr>
            <th>Chantier</th>
            <td colspan="3">{{ $data->job->site_address ?: '—' }}</td>
        </tr>
        <tr>
            <th>Désignation OF</th>
            <td colspan="3">{{ $data->workOrder->title }}</td>
        </tr>
        <tr>
            <th>Statut</th>
            <td>{{ $data->workOrder->status->getLabel() }}</td>
            <th>Priorité</th>
            <td>{{ $data->workOrder->priority->getLabel() }}</td>
        </tr>
        <tr>
            <th>Responsable</th>
            <td>{{ $data->workOrder->responsible?->name ?: '—' }}</td>
            <th>Libération</th>
            <td>{{ $formatDate($data->workOrder->released_at?->toIso8601String()) }}</td>
        </tr>
        <tr>
            <th>Début prévu</th>
            <td>{{ $formatDate($data->workOrder->planned_start_at?->toIso8601String()) }}</td>
            <th>Fin prévue</th>
            <td>{{ $formatDate($data->workOrder->planned_end_at?->toIso8601String()) }}</td>
        </tr>
        <tr>
            <th>Début réel</th>
            <td>{{ $formatDate($data->workOrder->actual_start_at?->toIso8601String()) }}</td>
            <th>Fin réelle</th>
            <td>{{ $formatDate($data->workOrder->actual_end_at?->toIso8601String()) }}</td>
        </tr>
        @if ($data->workOrder->description)
            <tr>
                <th>Description</th>
                <td colspan="3">{!! nl2br(e($data->workOrder->description)) !!}</td>
            </tr>
        @endif
    </table>

    <h2>Documents techniques applicables</h2>
    @if ($data->documents === [])
        <p class="muted">Aucun document technique applicable.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Titre</th>
                    <th>Catégorie</th>
                    <th>Indice</th>
                    <th>Version</th>
                    <th>Validé le</th>
                    <th>Référence utilisée</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data->documents as $document)
                    <tr>
                        <td>{{ $document['reference'] ?: '—' }}</td>
                        <td>{{ $document['title'] }}</td>
                        <td>{{ $document['category'] }}</td>
                        <td>{{ $document['revision'] ?: 'Non approuvée' }}</td>
                        <td>{{ $document['version_number'] ?: '—' }}</td>
                        <td>{{ $formatDate($document['validated_at']) }}</td>
                        <td>
                            {{ $document['is_frozen'] ? 'Figée à la libération' : 'Version courante' }}
                            @if ($document['has_newer_approved'])
                                <span class="badge status-warn">Révision plus récente</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Pièces et repères</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Repère</th>
                <th>Désignation</th>
                <th>Profil</th>
                <th>Nuance</th>
                <th>Longueur</th>
                <th class="right">Prévu</th>
                <th class="right">Fabriqué</th>
                <th class="right">Rebuté</th>
                <th class="right">Accepté</th>
                <th class="right">Restant</th>
                <th class="right">Poids (kg)</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data->items as $item)
                <tr>
                    <td><strong>{{ $item->mark }}</strong></td>
                    <td>{{ $item->designation }}</td>
                    <td>{{ $item->profile ?: '—' }}</td>
                    <td>{{ $item->steelGrade ?: '—' }}</td>
                    <td>{{ $item->lengthMm ? number_format((float) $item->lengthMm, 2, ',', ' ') . ' mm' : '—' }}</td>
                    <td class="right">{{ $formatQty($item->quantityPlanned) }}</td>
                    <td class="right">{{ $formatQty($item->quantityProduced) }}</td>
                    <td class="right">{{ $formatQty($item->quantityRejected) }}</td>
                    <td class="right">{{ $formatQty($item->quantityAccepted) }}</td>
                    <td class="right">{{ $formatQty($item->quantityRemaining) }}</td>
                    <td class="right">{{ $item->totalWeightKg !== null ? number_format((float) $item->totalWeightKg, 3, ',', ' ') : '—' }}</td>
                    <td>{{ $item->statusLabel }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="muted">Aucune pièce enregistrée.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Opérations de fabrication</h2>
    <table class="data">
        <thead>
            <tr>
                <th>#</th>
                <th>Code</th>
                <th>Opération</th>
                <th>Consignes</th>
                <th class="right">Estimé</th>
                <th class="right">Réel</th>
                <th>État</th>
                <th>Début</th>
                <th>Fin</th>
                <th>Opérateur(s)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data->operations as $operation)
                <tr>
                    <td>{{ $operation->position }}</td>
                    <td>{{ $operation->code }}</td>
                    <td>{{ $operation->name }}</td>
                    <td class="instructions">{!! nl2br(e($operation->instructions ?: '—')) !!}</td>
                    <td class="right">{{ $operation->estimatedDurationMinutes !== null ? $operation->estimatedDurationMinutes . ' min' : '—' }}</td>
                    <td class="right">{{ sprintf('%dh %02dm', intdiv($operation->realSeconds, 3600), intdiv($operation->realSeconds % 3600, 60)) }}</td>
                    <td>{{ $operation->statusLabel }}</td>
                    <td>{{ $formatDate($operation->startedAt) }}</td>
                    <td>{{ $formatDate($operation->completedAt) }}</td>
                    <td>{{ $operation->operators === [] ? '—' : implode(', ', $operation->operators) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted">Aucune opération enregistrée.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($data->lastCompletedOperation)
        <div class="section">
            <h3>Dernière opération terminée — information d'avancement</h3>
            <p class="muted small">
                {{ $data->lastCompletedOperation['code'] }} — {{ $data->lastCompletedOperation['name'] }}
                le {{ $formatDate($data->lastCompletedOperation['completed_at']) }}
                @if ($data->lastCompletedOperation['operator'])
                    par {{ $data->lastCompletedOperation['operator'] }}
                @endif.
                Cette information ne constitue pas le visa Atelier.
            </p>
        </div>
    @endif

    <h2>Qualité</h2>
    @if ($data->qualityControls === [] && $data->nonConformities === [])
        <p class="muted">Aucun contrôle qualité ni non-conformité enregistré.</p>
    @endif

    @if ($data->qualityControls !== [])
        <table class="data">
            <thead>
                <tr>
                    <th>Modèle</th>
                    <th>Type</th>
                    <th>Portée</th>
                    <th>Obligatoire</th>
                    <th>État</th>
                    <th class="right">Résultats</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data->qualityControls as $control)
                    <tr>
                        <td>{{ $control->templateCode }} — {{ $control->templateName }} (v{{ $control->templateVersion }})</td>
                        <td>{{ $control->controlTypeLabel }}</td>
                        <td>{{ $control->scopeLabel }}</td>
                        <td>{{ $control->isMandatory ? 'Oui' : 'Non' }}</td>
                        <td>{{ $control->statusLabel }}</td>
                        <td class="right">{{ $control->conformingResults }} / {{ $control->requiredResults }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($data->nonConformities !== [])
        <table class="data">
            <thead>
                <tr>
                    <th>Non-conformité</th>
                    <th>Gravité</th>
                    <th>État</th>
                    <th>Bloquante</th>
                    <th>Détectée le</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data->nonConformities as $nonConformity)
                    <tr>
                        <td>{{ $nonConformity['reference'] }}</td>
                        <td>{{ $nonConformity['severity'] }}</td>
                        <td>{{ $nonConformity['status'] }}</td>
                        <td>{{ $nonConformity['is_blocking'] ? 'Oui' : 'Non' }}</td>
                        <td>{{ $formatDate($nonConformity['detected_at']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Visas</h2>
    <table class="visa-grid">
        <tr>
            @foreach ([
                \App\DTO\WorkOrderPrintVisa::TYPE_BUREAU_ETUDES => "Bureau d'études",
                \App\DTO\WorkOrderPrintVisa::TYPE_LIBERATION => 'Libération',
                \App\DTO\WorkOrderPrintVisa::TYPE_QUALITE => 'Qualité',
                \App\DTO\WorkOrderPrintVisa::TYPE_ATELIER => 'Atelier',
            ] as $type => $label)
                @php $visa = $visas->get($type); @endphp
                <td>
                    <div class="visa-title">{{ $label }}</div>
                    @if ($visa && $visa->status === \App\DTO\WorkOrderPrintVisa::STATUS_EFFECTUE)
                        <div class="visa-name">{{ $visa->personName }}</div>
                        <div>{{ $visa->functionLabel ?: '—' }}</div>
                        <div>{{ $formatDate($visa->validatedAt) }}</div>
                    @elseif ($visa && $visa->status === \App\DTO\WorkOrderPrintVisa::STATUS_NON_RENSEIGNE)
                        <div class="badge {{ $statusClass($visa->status) }}">Non renseigné</div>
                        <div class="small">Validation incomplète</div>
                    @else
                        <div class="badge {{ $statusClass($visa?->status ?? 'non_effectue') }}">Non effectué</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <h2>Historique récent</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Date</th>
                <th>Événement</th>
                <th>Utilisateur</th>
                <th>Commentaire</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data->auditLogs as $log)
                <tr>
                    <td>{{ $formatDate($log['date']) }}</td>
                    <td>{{ $log['event'] }}</td>
                    <td>{{ $log['user'] ?: '—' }}</td>
                    <td>{{ $log['comment'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Aucun événement.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <div>Document généré par {{ $data->printedBy ?: 'système' }} le {{ $formatDate($data->printedAt) }} — {{ $data->workOrder->number }} — révision {{ $data->ofRevision }}.</div>
        <div class="hash">Empreinte des données : {{ $data->dataHash }}</div>
    </div>
</body>
</html>
