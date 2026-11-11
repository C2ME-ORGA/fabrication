@php
    /** @var array<string, mixed> $data */
    $formatDate = fn ($value): string => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
        : '—';
    $formatQty = fn (int|float|string|null $value): string => $value === null
        ? '—'
        : number_format((float) $value, 0, ',', ' ');
    $formatWeight = fn ($value): string => $value === null
        ? '—'
        : number_format((float) $value, 3, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $data['delivery_note_reference'] }} — Bon de livraison</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 10mm 16mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 9.5px; line-height: 1.35; }
        h1 { font-size: 20px; margin: 0; color: #111827; }
        h2 { font-size: 13px; margin: 16px 0 7px; padding-bottom: 3px; border-bottom: 1px solid #9ca3af; color: #111827; }
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
        .right { text-align: right; }
        .footer { margin-top: 14px; border-top: 1px solid #9ca3af; padding-top: 4px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="company">{{ $data['company_name'] }}</div>
                <div class="muted">Charpente métallique — fabrication</div>
            </td>
            <td class="doc-title">
                <div class="doc-title-label">BON DE LIVRAISON</div>
                <div class="number">{{ $data['delivery_note_reference'] }}</div>
                <div class="muted">Expédition : {{ $data['shipment_number'] }}</div>
            </td>
        </tr>
    </table>

    <h2>Références</h2>
    <table class="meta">
        <tr>
            <th>Ordre de fabrication</th>
            <td>{{ $data['work_order_number'] ?: '—' }}</td>
            <th>Révision OF</th>
            <td>{{ $data['of_revision'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Affaire</th>
            <td>{{ $data['job_code'] ?: '—' }} — {{ $data['job_name'] ?: '—' }}</td>
            <th>Client</th>
            <td>{{ $data['client_name'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Chantier</th>
            <td colspan="3">{{ $data['site_address'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Adresse de livraison</th>
            <td colspan="3">{{ $data['delivery_address'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Transporteur</th>
            <td>{{ $data['carrier_name'] ?: '—' }}</td>
            <th>Référence transporteur</th>
            <td>{{ $data['carrier_reference'] ?: '—' }}</td>
        </tr>
        <tr>
            <th>Validée le</th>
            <td>{{ $formatDate($data['validated_at']) }}</td>
            <th>Validée par</th>
            <td>{{ $data['validated_by'] ?: '—' }}</td>
        </tr>
    </table>

    <h2>Articles livrés</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Repère</th>
                <th>Désignation</th>
                <th class="right">Quantité</th>
                <th class="right">Poids unitaire (kg)</th>
                <th class="right">Poids total (kg)</th>
                <th>Observations</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['lines'] as $line)
                <tr>
                    <td><strong>{{ $line['mark'] }}</strong></td>
                    <td>{{ $line['designation'] }}</td>
                    <td class="right">{{ $formatQty($line['quantity']) }}</td>
                    <td class="right">{{ $formatWeight($line['unit_weight_kg']) }}</td>
                    <td class="right">{{ $formatWeight($line['total_weight_kg']) }}</td>
                    <td>{{ $line['notes'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Aucune ligne.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th class="right">{{ $formatQty($data['total_quantity']) }}</th>
                <th></th>
                <th class="right">{{ $formatWeight($data['total_weight_kg']) }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    <h2>Colisage</h2>
    <p>
        Nombre de colis : <strong>{{ $data['package_count'] }}</strong>
        @if ($data['packages'] !== [])
            — {{ implode(', ', array_column($data['packages'], 'code')) }}
        @endif
    </p>

    @if ($data['notes'])
        <h2>Observations</h2>
        <p>{!! nl2br(e($data['notes'])) !!}</p>
    @endif

    <div class="footer">
        <div>Document généré depuis les données persistées — {{ $data['company_name'] }} — {{ $data['shipment_number'] }} — {{ $data['work_order_number'] ?: 'OF non renseigné' }}.</div>
        <div class="small">Révision documentaire : {{ $data['of_revision'] ?: '—' }} — gabarit {{ $data['template_version'] }}.</div>
    </div>
</body>
</html>
